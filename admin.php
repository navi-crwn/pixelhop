<?php
/**
 * PixelHop - Admin Dashboard
 * Statistics and management interface
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            document.documentElement.setAttribute('data-theme', savedTheme || 'dark');
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 24px;
            transition: all 0.2s;
        }
        .stat-card:hover { background: var(--glass-bg-hover); transform: translateY(-2px); }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-text-primary);
            line-height: 1.2;
        }
        .stat-label {
            font-size: 0.875rem;
            color: var(--color-text-tertiary);
            margin-top: 4px;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .stat-icon.cyan { background: rgba(0, 245, 212, 0.15); color: var(--color-neon-cyan); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.15); color: var(--color-neon-purple); }
        .stat-icon.pink { background: rgba(236, 72, 153, 0.15); color: var(--color-neon-pink); }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }

        .chart-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 24px;
        }
        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: 16px;
        }

        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }
        .recent-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text-tertiary);
            border-bottom: 1px solid var(--glass-border);
        }
        .recent-table td {
            padding: 12px 16px;
            font-size: 0.875rem;
            color: var(--color-text-secondary);
            border-bottom: 1px solid var(--glass-border);
        }
        .recent-table tr:hover td { background: var(--glass-bg-hover); }
        .recent-table .thumb {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            object-fit: cover;
        }

        .format-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .format-badge.jpeg { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .format-badge.png { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .format-badge.gif { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; }
        .format-badge.webp { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

        .loading-skeleton {
            background: linear-gradient(90deg, var(--glass-bg) 25%, var(--glass-bg-hover) 50%, var(--glass-bg) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: var(--radius-md);
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
    </style>
</head>
<body class="min-h-screen font-sans">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob blob-cyan" style="top: -15%; left: -10%;"></div>
        <div class="blob blob-purple" style="bottom: -15%; right: -10%;"></div>
    </div>

    <header class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <nav class="max-w-7xl mx-auto">
            <div class="glass-card glass-card-header flex items-center justify-between px-5 py-3">
                <a href="/" class="flex items-center gap-3">
                    <img src="/assets/img/logo.svg" alt="PixelHop" class="w-8 h-8">
                    <span class="text-lg font-bold" style="color: var(--color-text-primary);">PixelHop</span>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-neon-cyan/20 text-neon-cyan">Dashboard</span>
                </a>
                <div class="flex items-center gap-3">
                    <button onclick="loadStats()" class="btn-secondary text-sm">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        Refresh
                    </button>
                    <a href="/" class="btn-primary text-sm">
                        <i data-lucide="home" class="w-4 h-4"></i>
                        Home
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <main class="relative z-10 pt-28 pb-16 px-4">
        <div class="max-w-7xl mx-auto">

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card">
                    <div class="stat-icon cyan">
                        <i data-lucide="image" class="w-6 h-6"></i>
                    </div>
                    <div class="stat-value" id="stat-total">-</div>
                    <div class="stat-label">Total Images</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i data-lucide="hard-drive" class="w-6 h-6"></i>
                    </div>
                    <div class="stat-value" id="stat-storage">-</div>
                    <div class="stat-label">Storage Used</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon pink">
                        <i data-lucide="calendar" class="w-6 h-6"></i>
                    </div>
                    <div class="stat-value" id="stat-today">-</div>
                    <div class="stat-label">Uploads Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i data-lucide="trending-up" class="w-6 h-6"></i>
                    </div>
                    <div class="stat-value" id="stat-week">-</div>
                    <div class="stat-label">This Week</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
                <div class="chart-container lg:col-span-2">
                    <div class="chart-title">Uploads (Last 30 Days)</div>
                    <canvas id="uploadsChart" height="200"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Format Distribution</div>
                    <canvas id="formatsChart" height="200"></canvas>
                </div>
            </div>

            <!-- Recent Uploads & Storage -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="chart-container lg:col-span-2">
                    <div class="chart-title">Recent Uploads</div>
                    <div class="overflow-x-auto">
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Filename</th>
                                    <th>Format</th>
                                    <th>Size</th>
                                    <th>Uploaded</th>
                                </tr>
                            </thead>
                            <tbody id="recent-uploads">
                                <tr>
                                    <td colspan="5" class="text-center py-8" style="color: var(--color-text-tertiary);">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Storage by Format</div>
                    <div id="storage-breakdown" class="space-y-3">
                        <div class="loading-skeleton h-8"></div>
                        <div class="loading-skeleton h-8"></div>
                        <div class="loading-skeleton h-8"></div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        lucide.createIcons();

        let uploadsChart = null;
        let formatsChart = null;


        Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--color-text-tertiary').trim() || '#71717a';
        Chart.defaults.borderColor = 'rgba(255,255,255,0.1)';

        async function loadStats() {
            try {
                const res = await fetch('/api/stats.php');
                const data = await res.json();

                if (data.success) {
                    updateStats(data.stats);
                    updateCharts(data.stats);
                    updateRecentUploads(data.recent_uploads);
                    updateStorageBreakdown(data.stats.storage_by_format);
                }
            } catch (err) {
                console.error('Failed to load stats:', err);
            }
        }

        function updateStats(stats) {
            document.getElementById('stat-total').textContent = stats.total_images.toLocaleString();
            document.getElementById('stat-storage').textContent = stats.total_size_formatted;
            document.getElementById('stat-today').textContent = stats.uploads_today.toLocaleString();
            document.getElementById('stat-week').textContent = stats.uploads_this_week.toLocaleString();
        }

        function updateCharts(stats) {
            const uploadsData = stats.uploads_last_30_days;
            const labels = Object.keys(uploadsData).map(d => {
                const date = new Date(d);
                return date.getDate();
            });
            const values = Object.values(uploadsData);


            if (uploadsChart) uploadsChart.destroy();
            if (formatsChart) formatsChart.destroy();


            const uploadsCtx = document.getElementById('uploadsChart').getContext('2d');
            uploadsChart = new Chart(uploadsCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Uploads',
                        data: values,
                        borderColor: '#00f5d4',
                        backgroundColor: 'rgba(0, 245, 212, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#00f5d4'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { maxTicksLimit: 10 }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' }
                        }
                    }
                }
            });


            const formatColors = {
                'JPEG': '#f59e0b',
                'PNG': '#10b981',
                'GIF': '#8b5cf6',
                'WEBP': '#3b82f6'
            };
            const formatsCtx = document.getElementById('formatsChart').getContext('2d');
            formatsChart = new Chart(formatsCtx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(stats.formats),
                    datasets: [{
                        data: Object.values(stats.formats),
                        backgroundColor: Object.keys(stats.formats).map(f => formatColors[f] || '#6b7280'),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 16 }
                        }
                    },
                    cutout: '60%'
                }
            });
        }

        function updateRecentUploads(uploads) {
            const tbody = document.getElementById('recent-uploads');

            if (uploads.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8" style="color: var(--color-text-tertiary);">No uploads yet</td></tr>';
                return;
            }

            tbody.innerHTML = uploads.map(img => `
                <tr>
                    <td>
                        <a href="/${img.id}" target="_blank">
                            <img src="${img.thumbnail || '/assets/img/placeholder.svg'}" alt="" class="thumb" loading="lazy">
                        </a>
                    </td>
                    <td>
                        <a href="/${img.id}" target="_blank" style="color: var(--color-text-primary);">
                            ${truncate(img.filename, 30)}
                        </a>
                    </td>
                    <td><span class="format-badge ${img.format.toLowerCase()}">${img.format}</span></td>
                    <td>${formatBytes(img.size)}</td>
                    <td>${timeAgo(img.uploaded_at)}</td>
                </tr>
            `).join('');
        }

        function updateStorageBreakdown(storage) {
            const container = document.getElementById('storage-breakdown');
            const entries = Object.entries(storage);

            if (entries.length === 0) {
                container.innerHTML = '<p style="color: var(--color-text-tertiary);">No data</p>';
                return;
            }

            const colors = { 'JPEG': '#f59e0b', 'PNG': '#10b981', 'GIF': '#8b5cf6', 'WEBP': '#3b82f6' };
            const total = entries.reduce((sum, [_, size]) => {
                const match = size.match(/([\d.]+)\s*(\w+)/);
                if (!match) return sum;
                const [, num, unit] = match;
                const multipliers = { 'B': 1, 'KB': 1024, 'MB': 1024*1024, 'GB': 1024*1024*1024 };
                return sum + (parseFloat(num) * (multipliers[unit] || 1));
            }, 0);

            container.innerHTML = entries.map(([format, size]) => {
                const match = size.match(/([\d.]+)\s*(\w+)/);
                let percentage = 0;
                if (match) {
                    const [, num, unit] = match;
                    const multipliers = { 'B': 1, 'KB': 1024, 'MB': 1024*1024, 'GB': 1024*1024*1024 };
                    percentage = ((parseFloat(num) * (multipliers[unit] || 1)) / total * 100).toFixed(1);
                }
                return `
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium" style="color: var(--color-text-primary);">${format}</span>
                            <span class="text-sm" style="color: var(--color-text-tertiary);">${size}</span>
                        </div>
                        <div class="h-2 rounded-full" style="background: var(--glass-border);">
                            <div class="h-2 rounded-full" style="width: ${percentage}%; background: ${colors[format] || '#6b7280'};"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function truncate(str, len) {
            if (!str) return '';
            return str.length > len ? str.substring(0, len) + '...' : str;
        }

        function formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function timeAgo(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'Just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
            if (seconds < 604800) return Math.floor(seconds / 86400) + 'd ago';
            return date.toLocaleDateString();
        }


        loadStats();
    </script>
</body>
</html>
