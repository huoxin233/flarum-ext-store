<?php

namespace Mattoid\Store\Upload;

use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 图标上传器（V-02 + V-17）
 *
 * 修复要点：
 * - V-02: 上传扩展名白名单 + 与 MIME 校验配合
 * - V-17: 返回相对路径与存储路径，方便跨环境迁移
 */
class StoreUploader
{
    /**
     * 允许的扩展名白名单（与 StoreValidator::getAllowedTypes 保持一致）
     * Extension whitelist (kept in sync with StoreValidator::getAllowedTypes)
     */
    private const ALLOWED_EXTS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    protected $uploadDir;

    public function __construct(Factory $filesystemFactory)
    {
        $this->uploadDir = $filesystemFactory->disk('mattoid-store');
    }

    /**
     * 上传文件并返回 [filename, url]
     * Upload a file and return [filename, url]
     *
     * @return array{filename: string, url: string}
     */
    public function upload(UploadedFileInterface $file): array
    {
        $ext = strtolower(pathinfo($file->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTS, true)) {
            // V-02: 即使 StoreValidator 漏放也保留兜底
            // V-02: keep a safety net even if StoreValidator misses
            throw new ValidationException(['message' => 'Invalid file extension']);
        }

        $filename = time().'_'.Str::random().'.'.$ext;
        $stream = $file->getStream();
        $stream->rewind();

        $this->uploadDir->put($filename, $stream->getContents());

        return [
            'filename' => $filename,
            'url' => $this->uploadDir->url($filename),
        ];
    }

    public function getFileMd5(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $stream->rewind();

        return md5($stream->getContents());
    }

    public function remove(string $filename): void
    {
        if ($this->uploadDir->exists($filename)) {
            $this->uploadDir->delete($filename);
        }
    }
}
