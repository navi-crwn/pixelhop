<?php
/**
 * Cron Job: Process SafeGuard Moderation Queue
 * 
 * Run every 1-5 minutes to process pending content verification
 * Priority: Guest uploads are processed first
 * 
 * Cron example (every 2 minutes):
 * *\/2 * * * * php /var/www/pichost/cron/process_moderation_queue.php >> /var/log/safeguard-queue.log 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../core/SafeGuard.php';

$limit = $argv[1] ?? 20; // Default 20 items per run
$includeRateLimited = in_array('--retry', $argv);

echo date('[Y-m-d H:i:s]') . " SafeGuard Queue Processor Started\n";

$safeGuard = new SafeGuard();

try {
    // Get helink database for queue access
    $helinkDb = new PDO(
        'mysql:host=127.0.0.1;dbname=helink_db;charset=utf8mb4',
        'helink_user',
        'VeryStrongPassword2024',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $limitInt = (int) $limit;
    
    // Build query - prioritize by priority (guest=50 > user=20)
    $sql = "SELECT * FROM moderation_queue 
            WHERE platform = 'pichost'
            AND (
                status = 'pending' 
                " . ($includeRateLimited ? "OR (status = 'rate_limited' AND (scheduled_at IS NULL OR scheduled_at <= NOW()))" : "") . "
            )
            AND retry_count < 5
            ORDER BY priority DESC, created_at ASC
            LIMIT {$limitInt}";
    
    $stmt = $helinkDb->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    if (empty($items)) {
        echo "  No pending items.\n";
        
        // Show stats
        $stats = $safeGuard->getQueueStats();
        echo "  Queue: {$stats['pending']} pending, {$stats['rate_limited']} rate-limited\n";
        exit(0);
    }
    
    echo "  Processing " . count($items) . " items...\n";
    
    $processed = 0;
    $flagged = 0;
    $takenDown = 0;
    $requeued = 0;
    $errors = 0;
    
    foreach ($items as $item) {
        try {
            // Mark as processing
            $stmt = $helinkDb->prepare("UPDATE moderation_queue SET status = 'processing', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$item->id]);
            
            // Analyze based on content type
            if ($item->content_type === 'image') {
                $result = $safeGuard->analyzeImage($item->content_url);
            } else {
                $result = $safeGuard->checkUrl($item->content_url);
            }
            
            // Check if rate limited
            if (!empty($result['rate_limited'])) {
                $requeued++;
                echo "  ⏳ Rate limited: {$item->content_id}\n";
                continue;
            }
            
            // Update queue status
            $newStatus = $result['safe'] ? 'verified' : 'flagged';
            $stmt = $helinkDb->prepare("
                UPDATE moderation_queue SET 
                    status = ?,
                    threat_type = ?,
                    threat_details = ?,
                    scan_results = ?,
                    last_scan_at = NOW(),
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $newStatus,
                $result['threat_type'] ?? null,
                $result['threat_details'] ?? null,
                json_encode($result),
                $item->id,
            ]);
            
            if (!$result['safe'] && empty($result['skipped'])) {
                $flagged++;
                echo "  ⚠️  Flagged: {$item->content_id} - " . ($result['threat_type'] ?? 'unknown') . "\n";
                
                // AUTO-TAKEDOWN for dangerous content
                $dangerousThreat = in_array($result['threat_type'] ?? '', [
                    SafeGuard::THREAT_CSAM,
                    SafeGuard::THREAT_MALWARE,
                    SafeGuard::THREAT_PHISHING,
                ]);
                
                if ($dangerousThreat) {
                    $takedownResult = $safeGuard->autoTakedown(
                        $item->content_id,
                        $result['threat_type'],
                        $result['threat_details'] ?? null
                    );
                    
                    if ($takedownResult) {
                        $takenDown++;
                        echo "  🚨 AUTO-TAKEDOWN: {$item->content_id}\n";
                    }
                }
            }
            
            $processed++;
            
            // Rate limit protection - 100ms delay
            usleep(100000);
            
        } catch (Exception $e) {
            $errors++;
            echo "  ❌ Error processing {$item->content_id}: " . $e->getMessage() . "\n";
            
            // Update retry count
            $stmt = $helinkDb->prepare("
                UPDATE moderation_queue SET 
                    status = 'pending',
                    retry_count = retry_count + 1,
                    last_error = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([substr($e->getMessage(), 0, 500), $item->id]);
        }
    }
    
    echo "\n📊 Summary:\n";
    echo "  Processed: {$processed}\n";
    echo "  Flagged: {$flagged}\n";
    echo "  Auto-Takedown: {$takenDown}\n";
    echo "  Requeued: {$requeued}\n";
    echo "  Errors: {$errors}\n";
    
    // Show remaining
    $stats = $safeGuard->getQueueStats();
    echo "\n📋 Remaining: {$stats['pending']} pending, {$stats['rate_limited']} rate-limited\n";
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

echo date('[Y-m-d H:i:s]') . " Done.\n";
