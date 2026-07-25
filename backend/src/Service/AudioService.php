<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Database;

/**
 * 音频文件管理服务
 *
 * 功能：
 *   1. 后台上传音频文件
 *   2. 音频列表管理
 *   3. 音频文件流式播放
 *   4. 默认播放设置
 *   5. 播放计数
 */
class AudioService
{
    private static string $uploadDir = '';
    private static array $allowedMimeTypes = [
        'audio/mpeg' => 'mp3',
        'audio/mp3'  => 'mp3',
        'audio/wav'  => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg'  => 'ogg',
        'audio/flac' => 'flac',
        'audio/aac'  => 'aac',
        'audio/m4a'  => 'm4a',
        'audio/x-m4a' => 'm4a',
    ];
    private static int $maxFileSize = 50 * 1024 * 1024; // 50MB

    private static function init(): void
    {
        if (self::$uploadDir === '') {
            $root = dirname(__DIR__, 2);
            self::$uploadDir = $root . '/storage/audio';
            if (!is_dir(self::$uploadDir)) {
                mkdir(self::$uploadDir, 0755, true);
            }
        }
    }

    public static function getUploadDir(): string
    {
        self::init();
        return self::$uploadDir;
    }

    /**
     * 获取音频列表（后台管理用）
     */
    public static function getList(int $page = 1, int $pageSize = 20): array
    {
        $pdo = Database::pdo();
        $offset = ($page - 1) * $pageSize;

        $countStmt = $pdo->query('SELECT COUNT(*) FROM audio_files');
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT * FROM audio_files ORDER BY sort_order ASC, id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$pageSize, $offset]);
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $item['file_size_text'] = self::formatSize((int)$item['file_size']);
            $item['duration_text'] = self::formatDuration((int)$item['duration']);
            $item['play_url'] = '/api/audio/play/' . $item['id'];
        }
        unset($item);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取启用的音频列表（APP 端用）
     */
    public static function getEnabledList(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            'SELECT id, title, artist, filename, file_size, duration, mime_type, is_default, sort_order 
             FROM audio_files 
             WHERE status = 1 
             ORDER BY sort_order ASC, id DESC'
        );
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $item['file_size_text'] = self::formatSize((int)$item['file_size']);
            $item['duration_text'] = self::formatDuration((int)$item['duration']);
            $item['play_url'] = '/api/audio/play/' . $item['id'];
        }
        unset($item);

        return [
            'list'  => $list,
            'total' => count($list),
        ];
    }

    /**
     * 获取音频详情
     */
    public static function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM audio_files WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['file_size_text'] = self::formatSize((int)$row['file_size']);
        $row['duration_text'] = self::formatDuration((int)$row['duration']);
        $row['play_url'] = '/api/audio/play/' . $row['id'];
        return $row;
    }

    /**
     * 上传音频文件
     */
    public static function upload(array $file, string $title = '', string $artist = ''): array
    {
        self::init();

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => '无效的上传文件'];
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            return ['success' => false, 'message' => '文件大小无效'];
        }
        if ($fileSize > self::$maxFileSize) {
            return ['success' => false, 'message' => '文件大小超过限制（最大 50MB）'];
        }

        $mimeType = (string)($file['type'] ?? '');
        $mimeTypeLower = strtolower($mimeType);
        if (!isset(self::$allowedMimeTypes[$mimeTypeLower])) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $allowedExts = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'];
            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'message' => '不支持的音频格式（支持 mp3/wav/ogg/flac/aac/m4a）'];
            }
        } else {
            $ext = self::$allowedMimeTypes[$mimeTypeLower];
        }

        $originalName = $file['name'] ?? 'audio';
        $safeName = preg_replace('/[^\w\-\.]/', '_', $originalName);
        $newFilename = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $targetPath = self::$uploadDir . '/' . $newFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'message' => '文件保存失败'];
        }

        chmod($targetPath, 0644);

        $duration = self::getDuration($targetPath);
        $actualMimeType = mime_content_type($targetPath) ?: $mimeType;

        if ($title === '') {
            $title = pathinfo($safeName, PATHINFO_FILENAME);
        }

        $pdo = Database::pdo();

        $countStmt = $pdo->query('SELECT COUNT(*) FROM audio_files');
        $hasDefault = (int)$countStmt->fetchColumn() > 0;
        $isDefault = $hasDefault ? 0 : 1;

        $stmt = $pdo->prepare(
            'INSERT INTO audio_files (title, artist, filename, file_path, file_size, duration, mime_type, is_default, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title,
            $artist,
            $newFilename,
            $targetPath,
            $fileSize,
            $duration,
            $actualMimeType,
            $isDefault,
            0,
        ]);

        $id = (int)$pdo->lastInsertId();

        return [
            'success'   => true,
            'message'   => '上传成功',
            'id'        => $id,
            'title'     => $title,
            'filename'  => $newFilename,
            'file_size' => $fileSize,
            'duration'  => $duration,
        ];
    }

    /**
     * 更新音频信息
     */
    public static function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }
        $pdo = Database::pdo();
        $fields = [];
        $params = [];

        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $params[] = (string)$data['title'];
        }
        if (isset($data['artist'])) {
            $fields[] = 'artist = ?';
            $params[] = (string)$data['artist'];
        }
        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }
        if (isset($data['status'])) {
            $fields[] = 'status = ?';
            $params[] = (int)$data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = 'UPDATE audio_files SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 设置为默认播放
     */
    public static function setDefault(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $pdo = Database::pdo();

        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE audio_files SET is_default = 0');
            $stmt = $pdo->prepare('UPDATE audio_files SET is_default = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }

    /**
     * 删除音频
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $pdo = Database::pdo();
        $row = self::getById($id);
        if ($row === null) {
            return false;
        }

        $filePath = $row['file_path'];
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $stmt = $pdo->prepare('DELETE FROM audio_files WHERE id = ?');
        $stmt->execute([$id]);
        return true;
    }

    /**
     * 获取音频播放文件路径（同时增加播放计数）
     */
    public static function getPlayFile(int $id): ?array
    {
        $audio = self::getById($id);
        if ($audio === null) {
            return null;
        }
        if ((int)$audio['status'] !== 1) {
            return null;
        }
        if (!is_file($audio['file_path'])) {
            return null;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('UPDATE audio_files SET play_count = play_count + 1 WHERE id = ?');
        $stmt->execute([$id]);

        return [
            'path'      => $audio['file_path'],
            'filename'  => $audio['filename'],
            'mime_type' => $audio['mime_type'],
            'size'      => (int)$audio['file_size'],
        ];
    }

    /**
     * 获取音频时长（秒）
     */
    private static function getDuration(string $filePath): int
    {
        if (!function_exists('shell_exec')) {
            return 0;
        }
        $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
             . escapeshellarg($filePath) . ' 2>&1';
        $output = @shell_exec($cmd);
        if ($output !== null) {
            $duration = (float)trim($output);
            if ($duration > 0) {
                return (int)round($duration);
            }
        }
        return 0;
    }

    private static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }
}
