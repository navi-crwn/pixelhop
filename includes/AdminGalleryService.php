<?php
/**
 * PixelHop - AdminGalleryService
 *
 * Agregasi data untuk halaman admin gallery. Logika dipindahkan dari
 * admin/gallery.php (controller) agar controller hanya menangani bootstrap,
 * auth/gate, AJAX action, dan render.
 *
 * Metadata sekarang di DB (tabel `images`) sebagai sumber kebenaran
 * (mode db_only). Service ini query DB langsung untuk list & agregasi
 * (pagination/filter/index) — lebih efisien daripada readAll() penuh
 * untuk halaman besar. Perilaku agregasi dipertahankan identik dengan
 * implementasi lama.
 */

require_once __DIR__ . '/Database.php';

final class AdminGalleryService
{
    /**
     * Kolom array/JSON pada tabel images (skema migrasi 003) yang
     * di-decode setelah dibaca dari DB.
     */
    private const DB_JSON_COLUMNS = [
        'urls',
        's3_keys',
        'storage_providers',
        'last_delete_error',
    ];

    /**
     * Agregasi data admin gallery.
     *
     * @param array $filters Filter & pagination request:
     *                        page, per_page, search, user, date, sort.
     * @return array Data siap render: images, stats, users_map, user_stats,
     *               unique_dates, dan state pagination.
     */
    public function getData(array $filters): array
    {
        $db = Database::getInstance();

        // Pagination
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 30);
        $offset = ($page - 1) * $perPage;

        // Filters
        $search = (string) ($filters['search'] ?? '');
        $filterUser = (string) ($filters['user'] ?? '');
        $filterDate = (string) ($filters['date'] ?? '');
        $sort = (string) ($filters['sort'] ?? 'newest');

        // Get all users for lookup (sama seperti versi lama).
        $usersStmt = $db->query('SELECT id, email, account_type FROM users');
        $usersMap = [];
        while ($row = $usersStmt->fetch(PDO::FETCH_ASSOC)) {
            $usersMap[$row['id']] = $row;
        }

        // Build WHERE untuk filter & search (perilaku identik dengan
        // filter PHP lama: search pada id/filename/user_email, user filter
        // guest vs member, date filter pada Y-m-d local).
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = '(i.id LIKE ? OR i.filename LIKE ? OR COALESCE(u.email, \'Guest\') LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filterUser !== '') {
            if ($filterUser === 'guest') {
                // Sama dengan $img['is_guest'] versi PHP: user_id kosong (0/NULL)
                // ATAU user_id yang tidak ada di tabel users (dangling FK).
                $where[] = '(i.user_id IS NULL OR i.user_id = 0 OR u.id IS NULL)';
            } else {
                $where[] = 'i.user_id = ?';
                $params[] = $filterUser;
            }
        }

        if ($filterDate !== '') {
            $dateStart = strtotime($filterDate . ' 00:00:00');
            $dateEnd = strtotime($filterDate . ' 00:00:00 +1 day');

            if ($dateStart === false || $dateEnd === false) {
                $where[] = '1=0';
            } else {
                $where[] = '(i.created_at >= ? AND i.created_at < ?)';
                $params[] = $dateStart;
                $params[] = $dateEnd;
            }
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // Total semua gambar (untuk stat "Images").
        $allCount = $this->countRows('SELECT COUNT(*) AS total FROM images');

        // Total size & views (agregat seluruh tabel, bukan hasil filter).
        $totalSize = $this->sumColumn('size');
        $totalViews = $this->sumColumn('view_count');

        // Guest vs member breakdown (identik dengan versi PHP: guest =
        // empty(user_id) ATAU user_id tidak ditemukan di usersMap).
        $guestCount = $this->countRows(
            'SELECT COUNT(*) AS total
               FROM images i
               LEFT JOIN users u ON u.id = i.user_id
              WHERE i.user_id IS NULL OR i.user_id = 0 OR u.id IS NULL'
        );
        $memberCount = $allCount - $guestCount;

        // Statistik per user untuk dropdown filter (guest dihitung terpisah).
        $userStats = ['guest' => 0];
        $groupedStats = Database::fetchAll('SELECT user_id, COUNT(*) AS total FROM images GROUP BY user_id');
        foreach ($groupedStats as $row) {
            $uid = $row['user_id'];
            if ($uid && isset($usersMap[$uid])) {
                $userStats[$uid] = (int) $row['total'];
            } else {
                $userStats['guest'] += (int) $row['total'];
            }
        }

        // Unique dates untuk dropdown filter (dibentuk di PHP agar timezone
        // local sama persis dengan implementasi lama).
        $uniqueDates = [];
        $dateRows = Database::fetchAll('SELECT created_at FROM images');
        foreach ($dateRows as $row) {
            $date = date('Y-m-d', $row['created_at'] ?? 0);
            $uniqueDates[$date] = ($uniqueDates[$date] ?? 0) + 1;
        }
        krsort($uniqueDates);
        $uniqueDates = array_slice($uniqueDates, 0, 30, true);

        // Sort mapping (identik dengan switch lama di admin/gallery.php).
        $sortMap = [
            'oldest' => 'i.created_at ASC',
            'largest' => 'i.size DESC',
            'smallest' => 'i.size ASC',
            'views' => 'i.view_count DESC',
            'name' => 'i.filename ASC',
            'newest' => 'i.created_at DESC',
        ];
        $orderBy = $sortMap[$sort] ?? 'i.created_at DESC';

        // Count hasil filter untuk pagination.
        $countSql = 'SELECT COUNT(*) AS total'
            . ' FROM images i'
            . ' LEFT JOIN users u ON u.id = i.user_id'
            . $whereSql;
        $totalFiltered = $this->countRows($countSql, $params);
        $totalPages = (int) ceil($totalFiltered / $perPage);

        // List halaman saat ini (LIMIT/OFFSET di-inline karena integer).
        $listSql = 'SELECT i.*, u.email AS user_email_from_join, u.id AS member_uid'
            . ' FROM images i'
            . ' LEFT JOIN users u ON u.id = i.user_id'
            . $whereSql
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $rows = Database::fetchAll($listSql, $params);
        $images = [];
        foreach ($rows as $row) {
            $img = $this->decodeDbRow($row);

            $img['id'] = $img['id'] ?? '';
            $memberUid = $img['member_uid'] ?? null;
            unset($img['member_uid']);

            if ($memberUid && isset($usersMap[$memberUid])) {
                $img['user_email'] = $usersMap[$memberUid]['email'];
                $img['is_guest'] = false;
            } else {
                $img['user_email'] = 'Guest';
                $img['is_guest'] = true;
            }

            unset($img['user_email_from_join']);
            $images[] = $img;
        }

        return [
            'images' => $images,
            'all_count' => $allCount,
            'total_filtered' => $totalFiltered,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'search' => $search,
            'filter_user' => $filterUser,
            'filter_date' => $filterDate,
            'sort' => $sort,
            'total_size' => $totalSize,
            'total_views' => $totalViews,
            'guest_count' => $guestCount,
            'member_count' => $memberCount,
            'users_map' => $usersMap,
            'user_stats' => $userStats,
            'unique_dates' => $uniqueDates,
        ];
    }

    /**
     * Hitung COUNT(*) dari query dan return int.
     */
    private function countRows(string $sql, array $params = []): int
    {
        $row = Database::fetchOne($sql, $params);

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Hitung SUM() kolom numerik pada tabel images.
     */
    private function sumColumn(string $column): int
    {
        if (!preg_match('/^[a-z_]+$/', $column)) {
            return 0;
        }

        $row = Database::fetchOne('SELECT COALESCE(SUM(' . $column . '), 0) AS total FROM images');

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Decode row DB -> array PHP. Kolom JSON di-json_decode; nilai null
     * dibiarkan null. Sama dengan decodeDbRow() milik ImageRepository.
     */
    private function decodeDbRow(array $row): array
    {
        foreach (self::DB_JSON_COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if (is_string($value) && trim($value) !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$column] = $decoded;
                }
            } elseif ($value === null) {
                $row[$column] = null;
            }
        }

        return $row;
    }
}
