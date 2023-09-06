<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\ExpirableElementInterface;
use craft\base\Field;
use craft\base\NestedElementInterface;
use craft\behaviors\DraftBehavior;
use craft\controllers\ElementIndexesController;
use craft\db\Connection;
use craft\db\FixedOrderExpression;
use craft\db\Query;
use craft\db\Table;
use craft\elements\actions\Delete;
use craft\elements\actions\DeleteForSite;
use craft\elements\actions\Duplicate;
use craft\elements\actions\NewChild;
use craft\elements\actions\NewSiblingAfter;
use craft\elements\actions\NewSiblingBefore;
use craft\elements\actions\Restore;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\conditions\entries\EntryCondition;
use craft\elements\conditions\entries\SectionConditionRule;
use craft\elements\conditions\entries\TypeConditionRule;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\enums\PropagationMethod;
use craft\events\DefineEntryTypesEvent;
use craft\events\ElementCriteriaEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\records\Entry as EntryRecord;
use craft\services\ElementSources;
use craft\services\Structures;
use craft\validators\DateCompareValidator;
use craft\validators\DateTimeValidator;
use craft\web\CpScreenResponseBehavior;
use DateTime;
use Illuminate\Support\Collection;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\Expression;
use yii\web\Response;

/**
 * Entry represents an entry element.
 *
 * @property int $typeId the entry type’s ID
 * @property int|null $authorId the entry author’s ID
 * @property EntryType $type the entry type
 * @property Section|null $section the entry’s section
 * @property ElementContainerFieldInterface|null $field the entry’s field
 * @property ElementInterface|null $owner the entry’s owner element
 * @property User|null $author the entry’s author
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class Entry extends Element implements NestedElementInterface, ExpirableElementInterface
{
    public const STATUS_LIVE = 'live';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';

    /**
     * @event DefineEntryTypesEvent The event that is triggered when defining the available entry types for the entry
     * @see getAvailableEntryTypes()
     * @since 3.6.0
     */
    public const EVENT_DEFINE_ENTRY_TYPES = 'defineEntryTypes';

    /**
     * @event ElementCriteriaEvent The event that is triggered when defining the parent selection criteria.
     * @see _parentOptionCriteria()
     * @since 4.4.0
     */
    public const EVENT_DEFINE_PARENT_SELECTION_CRITERIA = 'defineParentSelectionCriteria';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Entry');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('app', 'entry');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('app', 'Entries');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('app', 'entries');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'entry';
    }

    /**
     * @inheritdoc
     */
    public static function hasDrafts(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUris(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_LIVE => Craft::t('app', 'Live'),
            self::STATUS_PENDING => Craft::t('app', 'Pending'),
            self::STATUS_EXPIRED => Craft::t('app', 'Expired'),
            self::STATUS_DISABLED => Craft::t('app', 'Disabled'),
        ];
    }

    /**
     * @inheritdoc
     * @return EntryQuery The newly created [[EntryQuery]] instance.
     */
    public static function find(): EntryQuery
    {
        return new EntryQuery(static::class);
    }

    /**
     * @inheritdoc
     * @return EntryCondition
     */
    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(EntryCondition::class, [static::class]);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context): array
    {
        if ($context === ElementSources::CONTEXT_INDEX) {
            $sections = Craft::$app->getEntries()->getEditableSections();
            $editable = true;
        } else {
            $sections = Craft::$app->getEntries()->getAllSections();
            $editable = null;
        }

        $sectionIds = [];
        $singleSectionIds = [];
        $sectionsByType = [];

        foreach ($sections as $section) {
            $sectionIds[] = $section->id;

            if ($section->type == Section::TYPE_SINGLE) {
                $singleSectionIds[] = $section->id;
            } else {
                $sectionsByType[$section->type][] = $section;
            }
        }

        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('app', 'All entries'),
                'criteria' => [
                    'sectionId' => $sectionIds,
                    'editable' => $editable,
                ],
                'defaultSort' => ['postDate', 'desc'],
            ],
        ];

        if (!empty($singleSectionIds)) {
            $sources[] = [
                'key' => 'singles',
                'label' => Craft::t('app', 'Singles'),
                'criteria' => [
                    'sectionId' => $singleSectionIds,
                    'editable' => $editable,
                ],
                'defaultSort' => ['title', 'asc'],
            ];
        }

        $sectionTypes = [
            Section::TYPE_CHANNEL => Craft::t('app', 'Channels'),
            Section::TYPE_STRUCTURE => Craft::t('app', 'Structures'),
        ];

        $user = Craft::$app->getUser()->getIdentity();

        foreach ($sectionTypes as $type => $heading) {
            if (!empty($sectionsByType[$type])) {
                $sources[] = ['heading' => $heading];

                foreach ($sectionsByType[$type] as $section) {
                    /** @var Section $section */
                    $source = [
                        'key' => 'section:' . $section->uid,
                        'label' => Craft::t('site', $section->name),
                        'sites' => $section->getSiteIds(),
                        'data' => [
                            'type' => $type,
                            'handle' => $section->handle,
                        ],
                        'criteria' => [
                            'sectionId' => $section->id,
                            'editable' => $editable,
                        ],
                    ];

                    if ($type == Section::TYPE_STRUCTURE) {
                        $source['defaultSort'] = ['structure', 'asc'];
                        $source['structureId'] = $section->structureId;
                        $source['structureEditable'] = $user && $user->can("saveEntries:$section->uid");
                    } else {
                        $source['defaultSort'] = ['postDate', 'desc'];
                    }

                    $sources[] = $source;
                }
            }
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    public static function modifyCustomSource(array $config): array
    {
        try {
            /** @var EntryCondition $condition */
            $condition = Craft::$app->getConditions()->createCondition($config['condition']);
        } catch (InvalidConfigException) {
            return $config;
        }

        $rules = $condition->getConditionRules();

        // see if it's limited to one section
        /** @var SectionConditionRule|null $sectionRule */
        $sectionRule = ArrayHelper::firstWhere($rules, fn($rule) => $rule instanceof SectionConditionRule);
        $sectionOptions = $sectionRule?->getValues();

        if ($sectionOptions && count($sectionOptions) === 1) {
            $section = Craft::$app->getEntries()->getSectionByUid(reset($sectionOptions));
            if ($section) {
                $config['data']['handle'] = $section->handle;
            }
        }

        // see if it specifies any entry types
        /** @var TypeConditionRule|null $entryTypeRule */
        $entryTypeRule = ArrayHelper::firstWhere($rules, fn($rule) => $rule instanceof TypeConditionRule);
        $entryTypeOptions = $entryTypeRule?->getValues();

        if ($entryTypeOptions) {
            $entryType = Craft::$app->getEntries()->getEntryTypeByUid(reset($entryTypeOptions));
            if ($entryType) {
                $config['data']['entry-type'] = $entryType->handle;
            }
        }

        return $config;
    }

    /**
     * @inheritdoc
     */
    protected static function defineFieldLayouts(?string $source): array
    {
        if ($source !== null) {
            if ($source === '*') {
                $sections = Craft::$app->getEntries()->getAllSections();
            } elseif ($source === 'singles') {
                $sections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
            } else {
                $sections = [];
                if (preg_match('/^section:(.+)$/', $source, $matches)) {
                    $section = Craft::$app->getEntries()->getSectionByUid($matches[1]);
                    if ($section) {
                        $sections[] = $section;
                    }
                }
            }

            $entryTypes = array_values(array_unique(array_merge(
                ...array_map(fn(Section $section) => $section->getEntryTypes(), $sections),
            )));
        } else {
            // get all entry types, including those which may only be used by Matrix fields
            $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();
        }

        return array_map(fn(EntryType $entryType) => $entryType->getFieldLayout(), $entryTypes);
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source): array
    {
        // Get the selected site
        $controller = Craft::$app->controller;
        if ($controller instanceof ElementIndexesController) {
            /** @var ElementQuery $elementQuery */
            $elementQuery = $controller->getElementQuery();
        } else {
            $elementQuery = null;
        }
        $site = $elementQuery && $elementQuery->siteId
            ? Craft::$app->getSites()->getSiteById($elementQuery->siteId)
            : Craft::$app->getSites()->getCurrentSite();

        // Get the section we need to check permissions on
        if (preg_match('/^section:(\d+)$/', $source, $matches)) {
            $section = Craft::$app->getEntries()->getSectionById((int)$matches[1]);
        } elseif (preg_match('/^section:(.+)$/', $source, $matches)) {
            $section = Craft::$app->getEntries()->getSectionByUid($matches[1]);
        } else {
            $section = null;
        }

        // Now figure out what we can do with these
        $actions = [];
        $elementsService = Craft::$app->getElements();

        if ($section) {
            $user = Craft::$app->getUser()->getIdentity();

            if (
                $section->type == Section::TYPE_STRUCTURE &&
                $user->can('createEntries:' . $section->uid)
            ) {
                $newEntryUrl = 'entries/' . $section->handle . '/new';

                if (Craft::$app->getIsMultiSite()) {
                    $newEntryUrl .= '?site=' . $site->handle;
                }

                $actions[] = $elementsService->createAction([
                    'type' => NewSiblingBefore::class,
                    'newSiblingUrl' => $newEntryUrl,
                ]);

                $actions[] = $elementsService->createAction([
                    'type' => NewSiblingAfter::class,
                    'newSiblingUrl' => $newEntryUrl,
                ]);

                if ($section->maxLevels != 1) {
                    $actions[] = $elementsService->createAction([
                        'type' => NewChild::class,
                        'maxLevels' => $section->maxLevels,
                        'newChildUrl' => $newEntryUrl,
                    ]);
                }
            }

            // Duplicate
            if (
                $user->can("createEntries:$section->uid") &&
                $user->can("saveEntries:$section->uid")
            ) {
                $actions[] = Duplicate::class;

                if ($section->type === Section::TYPE_STRUCTURE && $section->maxLevels != 1) {
                    $actions[] = [
                        'type' => Duplicate::class,
                        'deep' => true,
                    ];
                }
            }

            // Delete?
            $actions[] = Delete::class;

            if ($user->can("deleteEntries:$section->uid")) {
                if (
                    $section->type === Section::TYPE_STRUCTURE &&
                    $section->maxLevels != 1 &&
                    $user->can("deletePeerEntries:$section->uid")
                ) {
                    $actions[] = [
                        'type' => Delete::class,
                        'withDescendants' => true,
                    ];
                }

                if ($section->propagationMethod === PropagationMethod::Custom && $section->getHasMultiSiteEntries()) {
                    $actions[] = DeleteForSite::class;
                }
            }
        }

        // Restore
        $actions[] = Restore::class;

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function includeSetStatusAction(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'slug' => Craft::t('app', 'Slug'),
            'uri' => Craft::t('app', 'URI'),
            [
                'label' => Craft::t('app', 'Section'),
                'orderBy' => function(int $dir, Connection $db) {
                    $sectionIds = Collection::make(Craft::$app->getEntries()->getAllSections())
                        ->sort(fn(Section $a, Section $b) => $dir === SORT_ASC
                            ? $a->name <=> $b->name
                            : $b->name <=> $a->name)
                        ->map(fn(Section $section) => $section->id)
                        ->all();
                    return new FixedOrderExpression('entries.sectionId', $sectionIds, $db);
                },
                'attribute' => 'section',
            ],
            [
                'label' => Craft::t('app', 'Entry Type'),
                'orderBy' => function(int $dir, Connection $db) {
                    $entryTypeIds = Collection::make(Craft::$app->getEntries()->getAllEntryTypes())
                        ->sort(fn(EntryType $a, EntryType $b) => $dir === SORT_ASC
                            ? $a->name <=> $b->name
                            : $b->name <=> $a->name)
                        ->map(fn(EntryType $type) => $type->id)
                        ->all();
                    return new FixedOrderExpression('entries.typeId', $entryTypeIds, $db);
                },
                'attribute' => 'type',
            ],
            [
                'label' => Craft::t('app', 'Post Date'),
                'orderBy' => function(int $dir) {
                    if ($dir === SORT_ASC) {
                        if (Craft::$app->getDb()->getIsMysql()) {
                            return new Expression('[[postDate]] IS NOT NULL DESC, [[postDate]] ASC');
                        } else {
                            return new Expression('[[postDate]] ASC NULLS LAST');
                        }
                    }
                    if (Craft::$app->getDb()->getIsMysql()) {
                        return new Expression('[[postDate]] IS NULL DESC, [[postDate]] DESC');
                    } else {
                        return new Expression('[[postDate]] DESC NULLS FIRST');
                    }
                },
                'attribute' => 'postDate',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'Expiry Date'),
                'orderBy' => 'expiryDate',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
            'id' => Craft::t('app', 'ID'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'section' => ['label' => Craft::t('app', 'Section')],
            'type' => ['label' => Craft::t('app', 'Entry Type')],
            'author' => ['label' => Craft::t('app', 'Author')],
            'slug' => ['label' => Craft::t('app', 'Slug')],
            'ancestors' => ['label' => Craft::t('app', 'Ancestors')],
            'parent' => ['label' => Craft::t('app', 'Parent')],
            'uri' => ['label' => Craft::t('app', 'URI')],
            'postDate' => ['label' => Craft::t('app', 'Post Date')],
            'expiryDate' => ['label' => Craft::t('app', 'Expiry Date')],
            'link' => ['label' => Craft::t('app', 'Link'), 'icon' => 'world'],
            'id' => ['label' => Craft::t('app', 'ID')],
            'uid' => ['label' => Craft::t('app', 'UID')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
            'revisionNotes' => ['label' => Craft::t('app', 'Revision Notes')],
            'revisionCreator' => ['label' => Craft::t('app', 'Last Edited By')],
            'drafts' => ['label' => Craft::t('app', 'Drafts')],
        ];

        // Hide Author & Last Edited By from Craft Solo
        if (Craft::$app->getEdition() !== Craft::Pro) {
            unset($attributes['author'], $attributes['revisionCreator']);
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        if ($source === '*') {
            $attributes[] = 'section';
        }

        if ($source !== 'singles') {
            $attributes[] = 'postDate';
            $attributes[] = 'expiryDate';
            $attributes[] = 'author';
        }

        $attributes[] = 'link';

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'author') {
            /** @phpstan-ignore-next-line */
            $sourceElementsWithAuthors = array_filter($sourceElements, function(self $entry) {
                return $entry->getAuthorId() !== null;
            });

            /** @phpstan-ignore-next-line */
            $map = array_map(function(self $entry) {
                return [
                    'source' => $entry->id,
                    'target' => $entry->getAuthorId(),
                ];
            }, $sourceElementsWithAuthors);

            return [
                'elementType' => User::class,
                'map' => $map,
                'criteria' => [
                    'status' => null,
                ],
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * Returns the GraphQL type name that entries should use, based on their entry type.
     *
     * @since 5.0.0
     */
    public static function gqlTypeName(EntryType $entryType): string
    {
        return sprintf('%s_Entry', $entryType->handle);
    }

    /**
     * @inheritdoc
     */
    public static function gqlScopesByContext(mixed $context): array
    {
        /** @var Section $section */
        $section = $context['section'];
        return [
            "sections.$section->uid",
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute): void
    {
        switch ($attribute) {
            case 'author':
                $elementQuery->andWith(['author', ['status' => null]]);
                break;
            default:
                parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
        }
    }

    /**
     * @var int|null Section ID
     * ---
     * ```php
     * echo $entry->sectionId;
     * ```
     * ```twig
     * {{ entry.sectionId }}
     * ```
     */
    public ?int $sectionId = null;

    /**
     * @var int|null Field ID
     * @since 5.0.0
     */
    public ?int $fieldId = null;

    /**
     * @var int|null Primary owner ID
     * @since 5.0.0
     */
    public ?int $primaryOwnerId = null;

    /**
     * @var int|null Owner ID
     * @since 5.0.0
     */
    public ?int $ownerId = null;

    /**
     * @var int|null Sort order
     * @since 5.0.0
     */
    public ?int $sortOrder = null;

    /**
     * @var bool Collapsed
     * @since 5.0.0
     */
    public bool $collapsed = false;

    /**
     * @var DateTime|null Post date
     * ---
     * ```php
     * echo Craft::$app->formatter->asDate($entry->postDate, 'short');
     * ```
     * ```twig
     * {{ entry.postDate|date('short') }}
     * ```
     */
    public ?DateTime $postDate = null;

    /**
     * @var DateTime|null Expiry date
     * ---
     * ```php
     * if ($entry->expiryDate) {
     *     echo Craft::$app->formatter->asDate($entry->expiryDate, 'short');
     * }
     * ```
     * ```twig
     * {% if entry.expiryDate %}
     *   {{ entry.expiryDate|date('short') }}
     * {% endif %}
     * ```
     */
    public ?DateTime $expiryDate = null;

    /**
     * @var bool Whether the entry was deleted along with its entry type
     * @see beforeDelete()
     * @internal
     */
    public bool $deletedWithEntryType = false;

    /**
     * @var bool Whether to save the entry’s row in the `elements_owners` table in [[afterSave()]].
     * @since 5.0.0
     */
    public bool $saveOwnership = true;

    /**
     * @var int|null Author ID
     * @see getAuthorId()
     * @see setAuthorId()
     */
    private ?int $_authorId = null;

    /**
     * @var User|null|false
     * @see getAuthor()
     * @see setAuthor()
     */
    private User|false|null $_author = null;

    /**
     * @var int|null Type ID
     * @see getType()
     */
    private ?int $_typeId = null;

    /**
     * @var ElementInterface|null The owner element, or false if [[ownerId]] is invalid
     * @see getOwner()
     * @see setOwner()
     */
    private ?ElementInterface $_owner = null;

    /**
     * @var int|null
     */
    private ?int $_oldTypeId = null;

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    public function init(): void
    {
        parent::init();
        $this->_oldTypeId = $this->_typeId;
    }

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        $names = parent::attributes();
        ArrayHelper::removeValue($names, 'deletedWithEntryType');
        ArrayHelper::removeValue($names, 'saveOwnership');
        $names[] = 'authorId';
        $names[] = 'typeId';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public function extraFields(): array
    {
        $names = parent::extraFields();
        $names[] = 'author';
        $names[] = 'section';
        $names[] = 'type';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'postDate' => Craft::t('app', 'Post Date'),
            'expiryDate' => Craft::t('app', 'Expiry Date'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sectionId', 'fieldId', 'ownerId', 'primaryOwnerId', 'typeId', 'sortOrder', 'authorId'], 'number', 'integerOnly' => true];
        $rules[] = [
            ['sectionId'],
            'required',
            'when' => fn() => !isset($this->fieldId),
        ];
        $rules[] = [['fieldId'], function(string $attribute) {
            if (isset($this->sectionId)) {
                $this->addError($attribute, Craft::t('app', '`sectionId` and `fieldId` cannot both be set on an entry.'));
            }
        }];
        $rules[] = [['postDate', 'expiryDate'], DateTimeValidator::class];

        $rules[] = [
            ['postDate'],
            DateCompareValidator::class,
            'operator' => '<',
            'compareAttribute' => 'expiryDate',
            'when' => fn() => $this->postDate && $this->expiryDate,
            'on' => self::SCENARIO_LIVE,
        ];

        $rules[] = [
            ['authorId'],
            'required',
            'when' => fn() => isset($this->sectionId) && $this->getSection()->type !== Section::TYPE_SINGLE,
            'on' => self::SCENARIO_LIVE,
        ];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected function shouldValidateTitle(): bool
    {
        return $this->getType()->hasTitleField;
    }

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        if (isset($this->fieldId)) {
            return $this->getField()->getSupportedSitesForElement($this);
        }

        $section = $this->getSection();
        /** @var Site[] $allSites */
        $allSites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(true), 'id');
        $sites = [];

        // If the section is leaving it up to entries to decide which sites to be propagated to,
        // figure out which sites the entry is currently saved in
        if (
            ($this->duplicateOf->id ?? $this->id) &&
            $section->propagationMethod === PropagationMethod::Custom
        ) {
            if ($this->id) {
                $currentSites = self::find()
                    ->status(null)
                    ->id($this->id)
                    ->site('*')
                    ->select('elements_sites.siteId')
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions($this->getIsRevision())
                    ->column();
            } else {
                $currentSites = [];
            }

            // If this is being duplicated from another element (e.g. a draft), include any sites the source element is saved to as well
            if (!empty($this->duplicateOf->id)) {
                array_push($currentSites, ...self::find()
                    ->status(null)
                    ->id($this->duplicateOf->id)
                    ->site('*')
                    ->select('elements_sites.siteId')
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions($this->duplicateOf->getIsRevision())
                    ->column()
                );
            }

            $currentSites = array_flip($currentSites);
        }

        foreach ($section->getSiteSettings() as $siteSettings) {
            switch ($section->propagationMethod) {
                case PropagationMethod::None:
                    $include = $siteSettings->siteId == $this->siteId;
                    $propagate = true;
                    break;
                case PropagationMethod::SiteGroup:
                    $include = $allSites[$siteSettings->siteId]->groupId == $allSites[$this->siteId]->groupId;
                    $propagate = true;
                    break;
                case PropagationMethod::Language:
                    $include = $allSites[$siteSettings->siteId]->language == $allSites[$this->siteId]->language;
                    $propagate = true;
                    break;
                case PropagationMethod::Custom:
                    $include = true;
                    // Only actually propagate to this site if it's the current site, or the entry has been assigned
                    // a status for this site, or the entry already exists for this site
                    $propagate = (
                        $siteSettings->siteId == $this->siteId ||
                        $this->getEnabledForSite($siteSettings->siteId) !== null ||
                        isset($currentSites[$siteSettings->siteId])
                    );
                    break;
                default:
                    $include = $propagate = true;
                    break;
            }

            if ($include) {
                $sites[] = [
                    'siteId' => $siteSettings->siteId,
                    'propagate' => $propagate,
                    'enabledByDefault' => $siteSettings->enabledByDefault,
                ];
            }
        }

        return $sites;
    }

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    protected function cacheTags(): array
    {
        $tags = [
            sprintf('entryType:%s', $this->getTypeId()),
        ];

        // Did the entry type just change?
        if ($this->getTypeId() !== $this->_oldTypeId) {
            $tags[] = "entryType:$this->_oldTypeId";
        }

        if (isset($this->sectionId)) {
            $tags[] = "section:$this->sectionId";
        }

        return $tags;
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException if [[siteId]] is not set to a site ID that the entry’s section is enabled for
     */
    public function getUriFormat(): ?string
    {
        if (isset($this->fieldId)) {
            return $this->getField()->getUriFormatForElement($this);
        }

        $sectionSiteSettings = $this->getSection()->getSiteSettings();

        if (!isset($sectionSiteSettings[$this->siteId])) {
            throw new InvalidConfigException('Entry’s section (' . $this->sectionId . ') is not enabled for site ' . $this->siteId);
        }

        return $sectionSiteSettings[$this->siteId]->uriFormat;
    }

    /**
     * @inheritdoc
     */
    protected function route(): array|string|null
    {
        // Make sure that the entry is actually live
        if (!$this->previewing && $this->getStatus() != self::STATUS_LIVE) {
            return null;
        }

        $section = $this->getSection();

        if (!$section) {
            return null;
        }

        // Make sure the section is set to have URLs for this site
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sectionSiteSettings = $section->getSiteSettings();

        if (!isset($sectionSiteSettings[$siteId]) || !$sectionSiteSettings[$siteId]->hasUrls) {
            return null;
        }

        return [
            'templates/render', [
                'template' => (string)$sectionSiteSettings[$siteId]->template,
                'variables' => [
                    'entry' => $this,
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getUiLabel(): string
    {
        if ($this->fieldId) {
            $entryType = $this->getType();
            if (!$entryType->hasTitleField && !$entryType->titleFormat) {
                return '';
            }
        }

        return parent::getUiLabel();
    }

    /**
     * @inheritdoc
     */
    protected function uiLabel(): ?string
    {
        if (!$this->fieldId && (!isset($this->title) || trim($this->title) === '')) {
            return Craft::t('app', 'Untitled entry');
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    protected function previewTargets(): array
    {
        if ($this->fieldId || Craft::$app->getEdition() === Craft::Pro) {
            return parent::previewTargets();
        }

        return array_map(function($previewTarget) {
            $previewTarget['label'] = Craft::t('site', $previewTarget['label']);
            return $previewTarget;
        }, $this->getSection()?->previewTargets ?? []);
    }

    /**
     * Returns the reference string to this element.
     *
     * @return string|null
     */
    public function getRef(): ?string
    {
        if (isset($this->fieldId)) {
            return null;
        }

        return $this->getSection()->handle . '/' . $this->slug;
    }

    /**
     * @inheritdoc
     */
    public function getIsTitleTranslatable(): bool
    {
        return ($this->getType()->titleTranslationMethod !== Field::TRANSLATION_METHOD_NONE);
    }

    /**
     * @inheritdoc
     */
    public function getTitleTranslationDescription(): ?string
    {
        return ElementHelper::translationDescription($this->getType()->titleTranslationMethod);
    }

    /**
     * @inheritdoc
     */
    public function getTitleTranslationKey(): string
    {
        $type = $this->getType();
        return ElementHelper::translationKey($this, $type->titleTranslationMethod, $type->titleTranslationKeyFormat);
    }

    /**
     * @inheritdoc
     */
    public function getIsSlugTranslatable(): bool
    {
        return ($this->getType()->slugTranslationMethod !== Field::TRANSLATION_METHOD_NONE);
    }

    /**
     * @inheritdoc
     */
    public function getSlugTranslationDescription(): ?string
    {
        return ElementHelper::translationDescription($this->getType()->slugTranslationMethod);
    }

    /**
     * @inheritdoc
     */
    public function getSlugTranslationKey(): string
    {
        $type = $this->getType();
        return ElementHelper::translationKey($this, $type->slugTranslationMethod, $type->slugTranslationKeyFormat);
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if (($fieldLayout = parent::getFieldLayout()) !== null) {
            return $fieldLayout;
        }
        try {
            $entryType = $this->getType();
        } catch (InvalidConfigException) {
            // The entry type was probably deleted
            return null;
        }
        return $entryType->getFieldLayout();
    }

    /**
     * @inheritdoc
     */
    public function getExpiryDate(): ?DateTime
    {
        return $this->expiryDate;
    }

    /**
     * Returns the entry’s section.
     *
     * ---
     * ```php
     * $section = $entry->section;
     * ```
     * ```twig
     * {% set section = entry.section %}
     * ```
     *
     * @return Section|null
     * @throws InvalidConfigException if [[sectionId]] is missing or invalid
     */
    public function getSection(): ?Section
    {
        if (!isset($this->sectionId)) {
            return null;
        }

        $section = Craft::$app->getEntries()->getSectionById($this->sectionId);
        if ($section === null) {
            throw new InvalidConfigException('Invalid section ID: ' . $this->sectionId);
        }
        return $section;
    }

    /**
     * @inheritdoc
     */
    public function getField(): ?ElementContainerFieldInterface
    {
        if (!isset($this->fieldId)) {
            return null;
        }

        $field = $this->getOwner()->getFieldLayout()->getFieldById($this->fieldId);
        if (!$field instanceof ElementContainerFieldInterface) {
            throw new InvalidConfigException("Invalid field ID: $this->fieldId");
        }
        return $field;
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    /**
     * Returns the entry type ID.
     *
     * @return int
     * @since 4.0.0
     */
    public function getTypeId(): int
    {
        return $this->getType()->id;
    }

    /**
     * Sets the entry type ID.
     *
     * @param int $typeId
     * @since 4.0.0
     */
    public function setTypeId(int $typeId): void
    {
        $this->_typeId = $typeId;
        $this->fieldLayoutId = null;
    }

    /**
     * Returns the available entry types for the entry.
     *
     * @return EntryType[]
     * @throws InvalidConfigException
     * @since 3.6.0
     */
    public function getAvailableEntryTypes(): array
    {
        if (isset($this->fieldId)) {
            /** @var EntryType[] $entryTypes */
            $entryTypes = $this->getField()->getFieldLayoutProviders();
        } else {
            $entryTypes = $this->getSection()->getEntryTypes();
        }

        // Fire a 'defineEntryTypes' event
        if ($this->hasEventHandlers(self::EVENT_DEFINE_ENTRY_TYPES)) {
            $event = new DefineEntryTypesEvent([
                'entryTypes' => $entryTypes,
            ]);
            $this->trigger(self::EVENT_DEFINE_ENTRY_TYPES, $event);
            $entryTypes = $event->entryTypes;
        }

        return $entryTypes;
    }

    /**
     * Returns the entry type.
     *
     * ---
     * ```php
     * $entryType = $entry->type;
     * ```
     * ```twig{1}
     * {% switch entry.type.handle %}
     *   {% case 'article' %}
     *     {% include "news/_article" %}
     *   {% case 'link' %}
     *     {% include "news/_link" %}
     * {% endswitch %}
     * ```
     *
     * @return EntryType
     * @throws InvalidConfigException if the section has no entry types
     */
    public function getType(): EntryType
    {
        if (!isset($this->_typeId)) {
            // Default to the section/field's first entry type
            $entryTypes = $this->getAvailableEntryTypes();
            if (!$entryTypes) {
                throw new InvalidConfigException('Entry is missing its type ID');
            }
            $this->_typeId = $entryTypes[0]->id;
        }

        return Craft::$app->getEntries()->getEntryTypeById($this->_typeId);
    }

    /**
     * @inheritdoc
     */
    public function getOwner(): ?ElementInterface
    {
        if (!isset($this->fieldId)) {
            return null;
        }

        if (!isset($this->_owner)) {
            $ownerId = $this->ownerId ?? $this->primaryOwnerId;
            if (!$ownerId) {
                throw new InvalidConfigException('Entry is missing its owner ID');
            }

            $this->_owner = Craft::$app->getElements()->getElementById($ownerId, null, $this->siteId);
            if (!isset($this->_owner)) {
                throw new InvalidConfigException("Invalid owner ID: $ownerId");
            }
        }

        return $this->_owner;
    }

    /**
     * @inheritdoc
     */
    public function setOwner(?ElementInterface $owner = null): void
    {
        $this->_owner = $owner;
        $this->ownerId = $owner->id ?? null;
    }

    /**
     * Returns the entry author ID.
     *
     * @return int|null
     * @since 4.0.0
     */
    public function getAuthorId(): ?int
    {
        return $this->_authorId;
    }

    /**
     * Sets the entry author ID.
     *
     * @param int|int[]|string|null $authorId
     * @since 4.0.0
     */
    public function setAuthorId(array|int|string|null $authorId): void
    {
        if ($authorId === '') {
            $authorId = null;
        }

        if (is_array($authorId)) {
            $this->_authorId = reset($authorId) ?: null;
        } else {
            $this->_authorId = $authorId;
        }

        $this->_author = null;
    }

    /**
     * Returns the entry’s author.
     *
     * ---
     * ```php
     * $author = $entry->author;
     * ```
     * ```twig
     * <p>By {{ entry.author.name }}</p>
     * ```
     *
     * @return User|null
     * @throws InvalidConfigException if [[authorId]] is set but invalid
     */
    public function getAuthor(): ?User
    {
        if (!isset($this->_author)) {
            if (!$this->getAuthorId()) {
                return null;
            }

            if (($this->_author = Craft::$app->getUsers()->getUserById($this->getAuthorId())) === null) {
                // The author is probably soft-deleted. Just no author is set
                $this->_author = false;
            }
        }

        return $this->_author ?: null;
    }

    /**
     * Sets the entry’s author.
     *
     * @param User|null $author
     */
    public function setAuthor(?User $author = null): void
    {
        $this->_author = $author;
        $this->setAuthorId($author?->id);
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): ?string
    {
        $status = parent::getStatus();

        if ($status == self::STATUS_ENABLED && $this->postDate) {
            $currentTime = DateTimeHelper::currentTimeStamp();
            $postDate = $this->postDate->getTimestamp();
            $expiryDate = $this->expiryDate?->getTimestamp();

            if ($postDate <= $currentTime && ($expiryDate === null || $expiryDate > $currentTime)) {
                return self::STATUS_LIVE;
            }

            if ($postDate > $currentTime) {
                return self::STATUS_PENDING;
            }

            return self::STATUS_EXPIRED;
        }

        return $status;
    }

    /**
     * @inheritdoc
     */
    public function createAnother(): ?self
    {
        /** @var self $entry */
        $entry = Craft::createObject([
            'class' => self::class,
            'sectionId' => $this->sectionId,
            'fieldId' => $this->fieldId,
            'primaryOwnerId' => $this->primaryOwnerId ?? $this->ownerId,
            'ownerId' => $this->primaryOwnerId ?? $this->ownerId,
            'sortOrder' => null,
            'typeId' => $this->typeId,
            'siteId' => $this->siteId,
        ]);

        $section = $this->getSection();
        if ($section) {
            // Set the default status based on the section's settings
            /** @var Section_SiteSettings $siteSettings */
            $siteSettings = ArrayHelper::firstWhere($section->getSiteSettings(), 'siteId', $this->siteId);
            $enabled = $siteSettings->enabledByDefault;
        } else {
            $enabled = true;
        }

        if (Craft::$app->getIsMultiSite() && count($entry->getSupportedSites()) > 1) {
            $entry->enabled = true;
            $entry->setEnabledForSite($enabled);
        } else {
            $entry->enabled = $enabled;
            $entry->setEnabledForSite(true);
        }

        // Structure parent
        if ($section?->type === Section::TYPE_STRUCTURE && $section->maxLevels !== 1) {
            $entry->setParentId($this->getParentId());
        }

        return $entry;
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }

        $section = $this->getSection();

        if (!$section) {
            return false;
        }

        if (!$user->can("viewEntries:$section->uid")) {
            return false;
        }

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            return (
                $this->creatorId === $user->id ||
                $user->can("viewPeerEntryDrafts:$section->uid")
            );
        }

        return (
            $section->type === Section::TYPE_SINGLE ||
            $this->getAuthorId() === $user->id ||
            $user->can("viewPeerEntries:$section->uid")
        );
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }

        $section = $this->getSection();

        if (!$section) {
            return false;
        }

        if (!$this->id) {
            return (
                $section->type !== Section::TYPE_SINGLE &&
                $user->can("createEntries:$section->uid")
            );
        }

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            return (
                $this->creatorId === $user->id ||
                $user->can("savePeerEntryDrafts:$section->uid")
            );
        }

        if (!$user->can("saveEntries:$section->uid")) {
            return false;
        }

        return (
            $section->type === Section::TYPE_SINGLE ||
            $this->getAuthorId() === $user->id ||
            $user->can("savePeerEntries:$section->uid")
        );
    }

    /**
     * @inheritdoc
     */
    public function canDuplicate(User $user): bool
    {
        if (parent::canDuplicate($user)) {
            return true;
        }

        $section = $this->getSection();

        if (!$section) {
            return false;
        }

        return (
            $section->type !== Section::TYPE_SINGLE &&
            $user->can("createEntries:$section->uid") &&
            $user->can("saveEntries:$section->uid")
        );
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }

        $section = $this->getSection();

        if (!$section) {
            return false;
        }

        if ($section->type === Section::TYPE_SINGLE && !$this->getIsDraft()) {
            return false;
        }

        if ($this->getIsDraft() && $this->getIsDerivative()) {
            /** @var static|DraftBehavior $this */
            return (
                $this->creatorId === $user->id ||
                $user->can("deletePeerEntryDrafts:$section->uid")
            );
        }

        if (!$user->can("deleteEntries:$section->uid")) {
            return false;
        }

        return (
            $this->getAuthorId() === $user->id ||
            $user->can("deletePeerEntries:$section->uid")
        );
    }

    /**
     * @inheritdoc
     */
    public function canDeleteForSite(User $user): bool
    {
        if (parent::canDeleteForSite($user)) {
            return true;
        }

        $section = $this->getSection();

        if (!$section) {
            return false;
        }

        return $section->propagationMethod === PropagationMethod::Custom;
    }

    /**
     * @inheritdoc
     */
    public function canCreateDrafts(User $user): bool
    {
        // Everyone with view permissions can create drafts
        return true;
    }

    /**
     * @inheritdoc
     */
    public function hasRevisions(): bool
    {
        return $this->getSection()?->enableVersioning ?? false;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        $section = $this->getSection();

        if (!$section) {
            // use the generic element editor URL
            return ElementHelper::elementEditorUrl($this, false);
        }

        $path = sprintf('entries/%s/%s', $section->handle, $this->getCanonicalId());

        // Ignore homepage/temp slugs
        if ($this->slug && !str_starts_with($this->slug, '__')) {
            $path .= sprintf('-%s', str_replace('/', '-', $this->slug));
        }

        return $path;
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('entries');
    }

    /**
     * @inheritdoc
     */
    protected function cpRevisionsUrl(): ?string
    {
        return sprintf('%s/revisions', $this->cpEditUrl());
    }

    /**
     * @inheritdoc
     */
    public function prepareEditScreen(Response $response, string $containerId): void
    {
        if ($this->fieldId) {
            $crumbs = [];
            $owner = $this->getOwner();

            do {
                array_unshift($crumbs, ['html' => Cp::elementChipHtml($owner)]);
                if (!$owner instanceof NestedElementInterface) {
                    break;
                }
                $owner = $owner->getOwner();
                if (!$owner) {
                    break;
                }
            } while (true);
        } else {
            $section = $this->getSection();

            $crumbs = [
                [
                    'label' => Craft::t('app', 'Entries'),
                    'url' => 'entries',
                ],
            ];

            if ($section->type === Section::TYPE_SINGLE) {
                $crumbs[] = [
                    'label' => Craft::t('app', 'Singles'),
                    'url' => 'entries/singles',
                ];
            } else {
                $crumbs[] = [
                    'label' => Craft::t('site', $section->name),
                    'url' => "entries/$section->handle",
                ];

                if ($section->type === Section::TYPE_STRUCTURE) {
                    $elementsService = Craft::$app->getElements();
                    $user = Craft::$app->getUser()->getIdentity();

                    foreach ($this->getCanonical()->getAncestors()->all() as $ancestor) {
                        if ($elementsService->canView($ancestor, $user)) {
                            $crumbs[] = [
                                'label' => $ancestor->title,
                                'url' => $ancestor->getCpEditUrl(),
                            ];
                        }
                    }
                }
            }
        }

        /** @var Response|CpScreenResponseBehavior $response */
        $response->crumbs($crumbs);
    }

    /**
     * @inheritdoc
     * @since 3.3.0
     */
    public function getGqlTypeName(): string
    {
        return self::gqlTypeName($this->getType());
    }

    /**
     * @inheritdoc
     */
    public function setEagerLoadedElements(string $handle, array $elements): void
    {
        if ($handle === 'author') {
            $this->_author = $elements[0] ?? false;
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    // Indexes, etc.
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'author':
                $author = $this->getAuthor();
                return $author ? Cp::elementChipHtml($author) : '';
            case 'section':
                return Html::encode(Craft::t('site', $this->getSection()->name));
            case 'type':
                try {
                    return Html::encode(Craft::t('site', $this->getType()->name));
                } catch (InvalidConfigException) {
                    return Craft::t('app', 'Unknown');
                }
            default:
                return parent::attributeHtml($attribute);
        }
    }

    /**
     * @inheritdoc
     */
    public function metaFieldsHtml(bool $static): string
    {
        $fields = [];
        $view = Craft::$app->getView();
        $section = $this->getSection();
        $user = Craft::$app->getUser()->getIdentity();

        if ($section?->type !== Section::TYPE_SINGLE) {
            // Type
            $fields[] = (function() use ($static, $view) {
                $entryTypes = $this->getAvailableEntryTypes();
                if (count($entryTypes) <= 1) {
                    return null;
                }

                $entryTypeOptions = [];
                $fieldLayoutIds = [];

                foreach ($entryTypes as $entryType) {
                    $entryTypeOptions[] = [
                        'label' => Craft::t('site', $entryType->name),
                        'value' => $entryType->id,
                    ];
                    $fieldLayoutIds["type-$entryType->id"] = $entryType->fieldLayoutId;
                }

                if (!$static) {
                    $typeInputId = $view->namespaceInputId('entryType');
                    $js = <<<EOD
(() => {
    const \$typeInput = $('#$typeInputId');
    const editor = \$typeInput.closest('form').data('elementEditor');
    if (editor) {
        editor.checkForm();
    }
})();
EOD;
                    $view->registerJs($js);
                }

                return Cp::selectFieldHtml([
                    'label' => Craft::t('app', 'Entry Type'),
                    'id' => 'entryType',
                    'name' => 'typeId',
                    'value' => $this->getTypeId(),
                    'options' => $entryTypeOptions,
                    'disabled' => $static,
                    'attribute' => 'typeId',
                    'errors' => $this->getErrors('typeId'),
                ]);
            })();
        }

        // Slug
        $fields[] = $this->slugFieldHtml($static);

        // Parent
        if ($section?->type === Section::TYPE_STRUCTURE && $section->maxLevels !== 1) {
            $fields[] = (function() use ($static, $section) {
                if ($parentId = $this->getParentId()) {
                    $parent = Craft::$app->getEntries()->getEntryById($parentId, $this->siteId, [
                        'drafts' => null,
                        'draftOf' => false,
                    ]);
                } else {
                    // If the entry already has structure data, use it. Otherwise, use its canonical entry
                    /** @var self|null $parent */
                    $parent = self::find()
                        ->siteId($this->siteId)
                        ->ancestorOf($this->lft ? $this : ($this->getIsCanonical() ? $this->id : $this->getCanonical(true)))
                        ->ancestorDist(1)
                        ->drafts(null)
                        ->draftOf(false)
                        ->status(null)
                        ->one();
                }

                return Cp::elementSelectFieldHtml([
                    'label' => Craft::t('app', 'Parent'),
                    'id' => 'parentId',
                    'name' => 'parentId',
                    'elementType' => self::class,
                    'selectionLabel' => Craft::t('app', 'Choose'),
                    'sources' => ["section:$section->uid"],
                    'criteria' => $this->_parentOptionCriteria($section),
                    'limit' => 1,
                    'elements' => $parent ? [$parent] : [],
                    'disabled' => $static,
                    'describedBy' => 'parentId-label',
                    'errors' => $this->getErrors('parentId'),
                ]);
            })();
        }

        if ($section && $section->type !== Section::TYPE_SINGLE) {
            // Author
            if (Craft::$app->getEdition() === Craft::Pro && $user->can("viewPeerEntries:$section->uid")) {
                $fields[] = (function() use ($static, $section) {
                    $author = $this->getAuthor();
                    return Cp::elementSelectFieldHtml([
                        'label' => Craft::t('app', 'Author'),
                        'id' => 'authorId',
                        'name' => 'authorId',
                        'elementType' => User::class,
                        'selectionLabel' => Craft::t('app', 'Choose'),
                        'criteria' => [
                            'can' => "viewEntries:$section->uid",
                        ],
                        'single' => true,
                        'elements' => $author ? [$author] : null,
                        'disabled' => $static,
                        'errors' => $this->getErrors('authorId'),
                    ]);
                })();
            }

            $isDeltaRegistrationActive = $view->getIsDeltaRegistrationActive();
            $view->setIsDeltaRegistrationActive(true);
            $view->registerDeltaName('postDate');
            $view->registerDeltaName('expiryDate');
            $view->setIsDeltaRegistrationActive($isDeltaRegistrationActive);

            // Post Date
            $fields[] = Cp::dateTimeFieldHtml([
                'label' => Craft::t('app', 'Post Date'),
                'id' => 'postDate',
                'name' => 'postDate',
                'value' => $this->_userPostDate(),
                'errors' => $this->getErrors('postDate'),
                'disabled' => $static,
            ]);

            // Expiry Date
            $fields[] = Cp::dateTimeFieldHtml([
                'label' => Craft::t('app', 'Expiry Date'),
                'id' => 'expiryDate',
                'name' => 'expiryDate',
                'value' => $this->expiryDate,
                'errors' => $this->getErrors('expiryDate'),
                'disabled' => $static,
            ]);
        }

        $fields[] = parent::metaFieldsHtml($static);

        return implode("\n", $fields);
    }

    /**
     * @inheritdoc
     */
    public function showStatusField(): bool
    {
        try {
            $showStatusField = $this->getType()->showStatusField;
        } catch (InvalidConfigException $e) {
            $showStatusField = true;
        }

        return $showStatusField;
    }

    private function _parentOptionCriteria(Section $section): array
    {
        $parentOptionCriteria = [
            'siteId' => $this->siteId,
            'sectionId' => $section->id,
            'status' => null,
            'drafts' => null,
            'draftOf' => false,
        ];

        // Prevent the current entry, or any of its descendants, from being selected as a parent
        if ($this->id) {
            $excludeIds = self::find()
                ->descendantOf($this)
                ->drafts(null)
                ->draftOf(false)
                ->status(null)
                ->ids();
            $excludeIds[] = $this->getCanonicalId();
            $parentOptionCriteria['id'] = array_merge(['not'], $excludeIds);
        }

        if ($section->maxLevels) {
            if ($this->id) {
                // Figure out how deep the ancestors go
                $maxDepth = self::find()
                    ->select('level')
                    ->descendantOf($this)
                    ->status(null)
                    ->leaves()
                    ->scalar();
                $depth = 1 + ($maxDepth ?: $this->level) - $this->level;
            } else {
                $depth = 1;
            }

            $parentOptionCriteria['level'] = sprintf('<=%s', $section->maxLevels - $depth);
        }

        if ($this->hasEventHandlers(self::EVENT_DEFINE_PARENT_SELECTION_CRITERIA)) {
            // Fire a defineParentSelectionCriteria event
            $event = new ElementCriteriaEvent([
                'criteria' => $parentOptionCriteria,
            ]);
            $this->trigger(self::EVENT_DEFINE_PARENT_SELECTION_CRITERIA, $event);
            return $event->criteria;
        }

        return $parentOptionCriteria;
    }

    /**
     * Updates the entry’s title, if its entry type has a dynamic title format.
     *
     * @since 3.0.3
     */
    public function updateTitle(): void
    {
        $entryType = $this->getType();

        if ($entryType->hasTitleField) {
            return;
        }

        if (!$entryType->titleFormat) {
            $this->title = null;
            return;
        }

        // Make sure that the locale has been loaded in case the title format has any Date/Time fields
        Craft::$app->getLocale();
        // Set Craft to the entry’s site’s language, in case the title format has any static translations
        $language = Craft::$app->language;
        $locale = Craft::$app->getLocale();
        $formattingLocale = Craft::$app->getFormattingLocale();
        $site = $this->getSite();
        $tempLocale = Craft::$app->getI18n()->getLocaleById($site->language);
        Craft::$app->language = $site->language;
        Craft::$app->set('locale', $tempLocale);
        Craft::$app->set('formattingLocale', $tempLocale);
        $title = Craft::$app->getView()->renderObjectTemplate($entryType->titleFormat, $this);
        if ($title !== '') {
            $this->title = $title;
        }
        Craft::$app->language = $language;
        Craft::$app->set('locale', $locale);
        Craft::$app->set('formattingLocale', $formattingLocale);
    }

    /**
     * Returns the Post Date value that should be shown on the edit form.
     *
     * @return DateTime|null
     */
    private function _userPostDate(): ?DateTime
    {
        if (!$this->postDate || ($this->getIsUnpublishedDraft() && $this->postDate == $this->dateCreated)) {
            // Pretend the post date hasn't been set yet, even if it has
            return null;
        }

        return $this->postDate;
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        if (!$this->getAuthorId() && !isset($this->fieldId) && $this->getSection()->type !== Section::TYPE_SINGLE) {
            $this->setAuthorId(Craft::$app->getUser()->getId());
        }

        if (
            !$this->_userPostDate() &&
            (
                in_array($this->scenario, [self::SCENARIO_LIVE, self::SCENARIO_DEFAULT]) ||
                (!$this->getIsDraft() && !$this->getIsRevision())
            )
        ) {
            // Default the post date to the current date/time
            $this->postDate = new DateTime();
            // ...without the seconds
            $this->postDate->setTimestamp($this->postDate->getTimestamp() - ($this->postDate->getTimestamp() % 60));
            // ...unless an expiry date is set in the past
            if ($this->expiryDate && $this->postDate >= $this->expiryDate) {
                $this->postDate = (clone $this->expiryDate)->modify('-1 day');
            }
        }

        return parent::beforeValidate();
    }

    /**
     * @inheritdoc
     * @throws Exception if reasons
     */
    public function beforeSave(bool $isNew): bool
    {
        $section = $this->getSection();
        if ($section) {
            // Make sure the entry has at least one revision if the section has versioning enabled
            if ($this->_shouldSaveRevision()) {
                $hasRevisions = self::find()
                    ->revisionOf($this)
                    ->site('*')
                    ->status(null)
                    ->exists();
                if (!$hasRevisions) {
                    /** @var self|null $currentEntry */
                    $currentEntry = self::find()
                        ->id($this->id)
                        ->site('*')
                        ->status(null)
                        ->one();

                    // May be null if the entry is currently stored as an unpublished draft
                    if ($currentEntry) {
                        $revisionNotes = 'Revision from ' . Craft::$app->getFormatter()->asDatetime($currentEntry->dateUpdated);
                        Craft::$app->getRevisions()->createRevision($currentEntry, $currentEntry->getAuthorId(), $revisionNotes);
                    }
                }
            }

            // Set the structure ID for Element::attributes() and afterSave()
            if ($section->type === Section::TYPE_STRUCTURE) {
                $this->structureId = $section->structureId;

                // Has the entry been assigned to a new parent?
                if (!$this->duplicateOf && $this->hasNewParent()) {
                    if ($parentId = $this->getParentId()) {
                        $parentEntry = Craft::$app->getEntries()->getEntryById($parentId, '*', [
                            'preferSites' => [$this->siteId],
                            'drafts' => null,
                            'draftOf' => false,
                        ]);

                        if (!$parentEntry) {
                            throw new InvalidConfigException("Invalid parent ID: $parentId");
                        }
                    } else {
                        $parentEntry = null;
                    }

                    $this->setParent($parentEntry);
                }
            }

            // Section type-specific stuff
            if ($section->type == Section::TYPE_SINGLE) {
                $this->setAuthorId(null);
                $this->expiryDate = null;
            }
        }

        $this->updateTitle();

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $section = $this->getSection();

            // Get the entry record
            if (!$isNew) {
                $record = EntryRecord::findOne($this->id);

                if (!$record) {
                    throw new InvalidConfigException("Invalid entry ID: $this->id");
                }
            } else {
                $record = new EntryRecord();
                $record->id = (int)$this->id;
            }

            $record->sectionId = $this->sectionId;
            $record->fieldId = $this->fieldId;
            $record->primaryOwnerId = $this->primaryOwnerId ?? $this->ownerId;
            $record->typeId = $this->getTypeId();
            $record->authorId = $this->getAuthorId();
            $record->postDate = Db::prepareDateForDb($this->postDate);
            $record->expiryDate = Db::prepareDateForDb($this->expiryDate);

            // Capture the dirty attributes from the record
            $dirtyAttributes = array_keys($record->getDirtyAttributes());

            $record->save(false);

            // ownerId will be null when creating a revision
            if (isset($this->fieldId, $this->ownerId) && $this->saveOwnership) {
                if (($isNew && $this->getIsCanonical()) || !isset($this->sortOrder)) {
                    $max = (new Query())
                        ->from(['eo' => Table::ELEMENTS_OWNERS])
                        ->innerJoin(['e' => Table::ENTRIES], '[[e.id]] = [[eo.elementId]]')
                        ->where([
                            'eo.ownerId' => $this->ownerId,
                            'e.fieldId' => $this->fieldId,
                        ])
                        ->max('sortOrder');
                    $this->sortOrder = $max ? $max + 1 : 1;
                }
                if ($isNew) {
                    Db::insert(Table::ELEMENTS_OWNERS, [
                        'elementId' => $this->id,
                        'ownerId' => $this->ownerId,
                        'sortOrder' => $this->sortOrder,
                    ]);
                } else {
                    Db::update(Table::ELEMENTS_OWNERS, [
                        'sortOrder' => $this->sortOrder,
                    ], [
                        'elementId' => $this->id,
                        'ownerId' => $this->ownerId,
                    ]);
                }
            }

            if ($this->getIsCanonical() && isset($this->sectionId) && $section->type == Section::TYPE_STRUCTURE) {
                // Has the parent changed?
                if ($this->hasNewParent()) {
                    $this->_placeInStructure($isNew, $section);
                }

                // Update the entry’s descendants, who may be using this entry’s URI in their own URIs
                if (!$isNew) {
                    Craft::$app->getElements()->updateDescendantSlugsAndUris($this, true, true);
                }
            }

            $this->setDirtyAttributes($dirtyAttributes);
        }

        parent::afterSave($isNew);
    }

    private function _placeInStructure(bool $isNew, Section $section): void
    {
        $parentId = $this->getParentId();
        $structuresService = Craft::$app->getStructures();

        // If this is a provisional draft and its new parent matches the canonical entry’s, just drop it from the structure
        if ($this->isProvisionalDraft) {
            $canonicalParentId = self::find()
                ->select(['elements.id'])
                ->ancestorOf($this->getCanonicalId())
                ->ancestorDist(1)
                ->status(null)
                ->scalar();

            if ($parentId == $canonicalParentId) {
                $structuresService->remove($this->structureId, $this);
                return;
            }
        }

        $mode = $isNew ? Structures::MODE_INSERT : Structures::MODE_AUTO;

        if (!$parentId) {
            if ($section->defaultPlacement === Section::DEFAULT_PLACEMENT_BEGINNING) {
                $structuresService->prependToRoot($this->structureId, $this, $mode);
            } else {
                $structuresService->appendToRoot($this->structureId, $this, $mode);
            }
        } else {
            if ($section->defaultPlacement === Section::DEFAULT_PLACEMENT_BEGINNING) {
                $structuresService->prepend($this->structureId, $this, $this->getParent(), $mode);
            } else {
                $structuresService->append($this->structureId, $this, $this->getParent(), $mode);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        // Save a new revision?
        if ($this->_shouldSaveRevision()) {
            Craft::$app->getRevisions()->createRevision($this, $this->revisionCreatorId, $this->revisionNotes);
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $data = [
            'deletedWithEntryType' => $this->deletedWithEntryType,
            'parentId' => null,
        ];

        if ($this->structureId) {
            // Remember the parent ID, in case the entry needs to be restored later
            $parentId = $this->getAncestors(1)
                ->status(null)
                ->select(['elements.id'])
                ->scalar();
            if ($parentId) {
                $data['parentId'] = $parentId;
            }
        }

        Db::update(Table::ENTRIES, $data, [
            'id' => $this->id,
        ], [], false);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function afterRestore(): void
    {
        $section = $this->getSection();
        if ($section->type === Section::TYPE_STRUCTURE) {
            // Add the entry back into its structure
            /** @var self|null $parent */
            $parent = self::find()
                ->structureId($section->structureId)
                ->innerJoin(['j' => Table::ENTRIES], '[[j.parentId]] = [[elements.id]]')
                ->andWhere(['j.id' => $this->id])
                ->one();

            if (!$parent) {
                Craft::$app->getStructures()->appendToRoot($section->structureId, $this);
            } else {
                Craft::$app->getStructures()->append($section->structureId, $this, $parent);
            }
        }

        parent::afterRestore();
    }

    /**
     * @inheritdoc
     */
    public function afterMoveInStructure(int $structureId): void
    {
        // Was the entry moved within its section's structure?
        $section = $this->getSection();

        if ($section->type == Section::TYPE_STRUCTURE && $section->structureId == $structureId) {
            Craft::$app->getElements()->updateElementSlugAndUri($this, true, true, true);

            // If this is the canonical entry, update its drafts
            if ($this->getIsCanonical()) {
                /** @var self[] $drafts */
                $drafts = self::find()
                    ->draftOf($this)
                    ->status(null)
                    ->site('*')
                    ->unique()
                    ->all();
                $structuresService = Craft::$app->getStructures();
                $lastElement = $this;

                foreach ($drafts as $draft) {
                    $structuresService->moveAfter($section->structureId, $draft, $lastElement);
                    $lastElement = $draft;
                }
            }
        }

        parent::afterMoveInStructure($structureId);
    }

    /**
     * Returns whether the entry should be saving revisions on save.
     *
     * @return bool
     */
    private function _shouldSaveRevision(): bool
    {
        return (
            $this->id &&
            !$this->propagating &&
            !$this->resaving &&
            !$this->getIsDraft() &&
            !$this->getIsRevision() &&
            $this->getSection()?->enableVersioning
        );
    }
}
