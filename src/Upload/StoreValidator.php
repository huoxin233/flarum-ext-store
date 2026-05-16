<?php

namespace Mattoid\Store\Upload;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\AvatarValidator;
use Illuminate\Validation\Factory;
use Intervention\Image\ImageManager;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 上传文件校验器（V-02）
 * Upload file validator (V-02).
 *
 * 修复要点：
 * - 恢复 assertFileMimes（之前被注释）
 * - 通过 getRules 同时校验 max size 与 mimes
 *
 * Fixes:
 * - Re-enable assertFileMimes (previously commented out)
 * - Use getRules to enforce both max size and mimes via Laravel validator
 */
class StoreValidator extends AvatarValidator
{
    protected $config;

    public function __construct(Factory $validator, TranslatorInterface $translator, ImageManager $imageManager, SettingsRepositoryInterface $config)
    {
        parent::__construct($validator, $translator, $imageManager);
        $this->config = $config;
    }

    protected function getRules(): array
    {
        return [
            'file' => [
                'required',
                'max:'.$this->getMaxSize(),
                'mimes:'.implode(',', $this->getAllowedTypes()),
            ],
        ];
    }

    public function assertValid(array $attributes)
    {
        $this->laravelValidator = $this->makeValidator($attributes);

        $this->assertFileRequired($attributes['file']);
        $this->assertFileMimes($attributes['file']);     // V-02: re-enabled
        $this->assertFileSize($attributes['file']);
    }

    protected function getAllowedTypes(): array
    {
        return ['png', 'jpeg', 'jpg', 'webp', 'gif'];
    }

    protected function getMaxSize()
    {
        return 4096;
    }
}
