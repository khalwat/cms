<?php
declare(strict_types = 1);
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\fs;

use Craft;

/**
 * The temporary volume class. Handles the implementation of a temporary volume
 * Craft.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class Temp extends Local
{
    /**
     * @inheritdoc
     */
    public bool $hasUrls = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Temp Folder');
    }

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Config normalization
        if (!isset($config['path'])) {
            $config['path'] = Craft::$app->getPath()->getTempAssetUploadsPath();
        }
        if (!isset($config['name'])) {
            $config['name'] = Craft::t('app', 'Temporary filesystem');
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return null;
    }
}
