<?php

namespace Backend\Modules\MediaLibrary\Domain\MediaItem;

use Backend\Modules\MediaLibrary\Component\StorageProvider\LiipImagineBundleStorageProviderInterface;
use Backend\Modules\MediaLibrary\Component\StorageProvider\StorageProviderInterface;
use Backend\Modules\MediaLibrary\Domain\MediaGroupMediaItem\MediaGroupMediaItem;
use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Exception;
use JsonSerializable;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Backend\Modules\MediaLibrary\Domain\MediaFolder\MediaFolder;
use Backend\Core\Engine\Model;

/**
 * MediaItem
 *
 * @ORM\Entity(repositoryClass="Backend\Modules\MediaLibrary\Domain\MediaItem\MediaItemRepository")
 * @ORM\HasLifecycleCallbacks
 */
class MediaItem implements JsonSerializable
{
    /**
     * @var string
     *
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="UUID")
     * @ORM\Column(type="guid")
     */
    private $id;

    /**
     * @var MediaFolder
     *
     * @ORM\ManyToOne(
     *      targetEntity="Backend\Modules\MediaLibrary\Domain\MediaFolder\MediaFolder",
     *      inversedBy="items",
     *      cascade="persist"
     * )
     * @ORM\JoinColumn(
     *      name="mediaFolderId",
     *      referencedColumnName="id",
     *      onDelete="cascade",
     *      nullable=false
     * )
     */
    protected $folder;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    protected $userId;

    /**
     * @var StorageType
     *
     * @ORM\Column(type="media_item_storage_type", options={"default"="local"})
     */
    protected $storageType;

    /**
     * @var Type
     *
     * @ORM\Column(type="media_item_type")
     */
    protected $type;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", nullable=true)
     */
    protected $mime;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", nullable=true)
     */
    protected $shardingFolderName;

    /**
     * @var string
     *
     * @ORM\Column(type="string")
     */
    protected $url;

    /**
     * @var string
     *
     * @ORM\Column(type="string")
     */
    protected $title;

    /**
     * @var int|null
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $size;

    /**
     * @var int|null
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $width;

    /**
     * @var int|null
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $height;

    /**
     * @var AspectRatio|null
     *
     * @ORM\Column(type="media_item_aspect_ratio", nullable=true)
     */
    protected $aspectRatio;

    /**
     * @var DateTime
     *
     * @ORM\Column(type="datetime")
     */
    protected $createdOn;

    /**
     * @var DateTime
     *
     * @ORM\Column(type="datetime")
     */
    protected $editedOn;

    /**
     * @var Collection<MediaGroupMediaItem>
     *
     * @ORM\OneToMany(
     *     targetEntity="Backend\Modules\MediaLibrary\Domain\MediaGroupMediaItem\MediaGroupMediaItem",
     *     mappedBy="item",
     *     cascade={"persist","merge"},
     *     orphanRemoval=true
     * )
     */
    protected $groups;

    private function __construct(
        string $title,
        string $url,
        Type $type,
        StorageType $storageType,
        MediaFolder $folder,
        int $userId
    ) {
        $this->folder = $folder;
        $this->userId = $userId;
        $this->type = $type;
        $this->storageType = $storageType;
        $this->url = $url;
        $this->title = $title;
        $this->createdOn = new DateTime();
        $this->editedOn = new DateTime();
        $this->groups = new ArrayCollection();
    }

    public static function createFromLocalStorageType(
        string $path,
        MediaFolder $folder,
        int $userId
    ): self {
        $file = self::getFileFromPath($path);

        $mediaItem = new self(
            self::getTitleFromFile($file),
            $file->getFilename(),
            self::getTypeFromFile($file),
            StorageType::local(),
            $folder,
            $userId
        );

        $mediaItem->setForFile($file);

        if (!in_array($mediaItem->getMime(), ['image/svg', 'image/svg+xml'])) {
            $mediaItem->setResolutionFromPath($path);
        } else {
            $mediaItem->setResolutionForSvg($path);
        }

        return $mediaItem;
    }

    private function setForFile(File $file)
    {
        $this->mime = $file->getMimeType();
        $this->size = $file->getSize();

        // Define sharding folder (getPath gets the path without the trailing slash)
        $this->shardingFolderName = basename($file->getPath());
    }

    private function setResolutionFromPath(string $path)
    {
        if ($this->getType()->isImage()) {
            try {
                [$width, $height] = getimagesize($path);
            } catch (Exception $e) {
                throw new Exception(
                    'Error happened when creating MediaItem from path "' . $path . '". The error = ' . $e->getMessage()
                );
            }

            $this->setResolution($width, $height);
        }
    }

    private function setResolutionForSvg(string $path)
    {
        if (!$this->getType()->isImage()) {
            throw new \LogicException('SVG file must be of type image.');
        }

        try {
            $xmlget = simplexml_load_file($path);
            $viewBoxString = $xmlget->attributes()->viewBox;
            if ($viewBoxString !== null) {
                $viewBox = explode(' ', $viewBoxString);
                $width = (int) $viewBox[2];
                $height = (int) $viewBox[3];
            } else {
                $width = (int) $xmlget->attributes()->width;
                $height = (int) $xmlget->attributes()->height;
            }

            // no size defined in svg
            if ($width === 0 && $height === 0) {
                $width = 200;
                $height = 200;
            }
        } catch (Exception $e) {
            throw new Exception(
                'Error happened when creating MediaItem from path "' . $path . '". The error = ' . $e->getMessage()
            );
        }

        $this->setResolution($width, $height);
    }

    public static function createFromMovieUrl(
        StorageType $movieStorageType,
        string $movieId,
        string $movieTitle,
        MediaFolder $folder,
        int $userId
    ): MediaItem {
        return new self(
            $movieTitle,
            $movieId,
            Type::movie(),
            $movieStorageType,
            $folder,
            $userId
        );
    }

    public static function fromDataTransferObject(MediaItemDataTransferObject $mediaItemDataTransferObject): ?MediaItem
    {
        if (!$mediaItemDataTransferObject->hasExistingMediaItem()) {
            throw new \BadFunctionCallException('This method can not be used to create a new media item');
        }

        $mediaItem = $mediaItemDataTransferObject->getMediaItemEntity();

        $mediaItem->title = $mediaItemDataTransferObject->title;
        $mediaItem->folder = $mediaItemDataTransferObject->folder;
        $mediaItem->userId = $mediaItemDataTransferObject->userId;
        $mediaItem->url = $mediaItemDataTransferObject->url;

        return $mediaItem;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'folder' => $this->folder,
            'userId' => $this->userId,
            'type' => (string) $this->type,
            'storageType' => (string) $this->storageType,
            'mime' => $this->mime,
            'shardingFolderName' => $this->shardingFolderName,
            'url' => $this->url,
            'fullUrl' => $this->getFullUrl(),
            'title' => $this->title,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'createdOn' => $this->createdOn->getTimestamp(),
            'editedOn' => $this->editedOn->getTimestamp(),
            'source' => $this->getWebPath(),
            'preview_source' => $this->getWebPath('backend'),
            'direct_url' => $this->getWebPath(),
            $this->type->getType() => true,
        ];
    }

    private static function getFileFromPath(string $path): File
    {
        try {
            // Define file from path
            $file = new File($path);
        } catch (FileNotFoundException $e) {
            throw new Exception(
                'This is not a valid file: "' . $path . '".'
            );
        }

        // We don't have a file
        if (!$file->isFile()) {
            throw new Exception(
                'The given source is not a file.'
            );
        }

        return $file;
    }

    private static function getTitleFromFile(File $file): string
    {
        return str_replace('.' . $file->getExtension(), '', $file->getFilename());
    }

    private static function getTypeFromFile(File $file): Type
    {
        $extensionType = Type::fromExtension($file->getExtension());

        try {
            $mimeTypeType = Type::fromMimeType($file->getMimeType());
        } catch (Exception $exception) {
            return $extensionType;
        }

        if (!$extensionType->equals($mimeTypeType)) {
            return $extensionType;
        }

        return $mimeTypeType;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFolder(): MediaFolder
    {
        return $this->folder;
    }

    public function setFolder(MediaFolder $folder): self
    {
        $this->folder = $folder;

        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStorageType(): StorageType
    {
        return $this->storageType;
    }

    public function setStorageType(StorageType $storageType)
    {
        $this->storageType = $storageType;
    }

    public function getType(): Type
    {
        return $this->type;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getScardingFolderName(): ?string
    {
        return $this->shardingFolderName;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title)
    {
        $this->title = $title;
    }

    public function getFullUrl(): string
    {
        return $this->getScardingFolderName() . '/' . $this->getUrl();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setResolution(int $width, int $height): self
    {
        $this->width = $width;
        $this->height = $height;

        $this->refreshAspectRatio();

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCreatedOn(): DateTime
    {
        return $this->createdOn;
    }

    public function getEditedOn(): DateTime
    {
        return $this->editedOn;
    }

    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function hasGroups(): bool
    {
        return $this->groups->count() > 0;
    }

    public function getAbsolutePath(): string
    {
        return Model::get('media_library.manager.storage')->getStorageProvider(
            $this->getStorageType()
        )->getAbsolutePath($this);
    }

    public function getAbsoluteWebPath(): string
    {
        return Model::get('media_library.manager.storage')->getStorageProvider(
            $this->getStorageType()
        )->getAbsoluteWebPath($this);
    }

    public function getLinkHTML(): string
    {
        return Model::get('media_library.manager.storage')->getStorageProvider($this->getStorageType())->getLinkHTML(
            $this
        );
    }

    public function getIncludeHTML(): string
    {
        return Model::get('media_library.manager.storage')->getStorageProvider($this->getStorageType())->getIncludeHTML(
            $this
        );
    }

    public function getAspectRatio(): AspectRatio
    {
        return $this->aspectRatio;
    }

    public function getWebPath(string $liipImagineBundleFilter = null): string
    {
        /** @var StorageProviderInterface $storage */
        $storage = Model::get('media_library.manager.storage')->getStorageProvider($this->getStorageType());

        if (!$storage instanceof LiipImagineBundleStorageProviderInterface || $liipImagineBundleFilter === null) {
            return $storage->getWebPath($this);
        }

        return $storage->getWebPathWithFilter($this, $liipImagineBundleFilter);
    }

    public function getThumbnail(string $liipImagineBundleFilter = null): string
    {
        /** @var StorageProviderInterface $storage */
        $storage = Model::get('media_library.manager.storage')->getStorageProvider($this->getStorageType());

        if (!$storage instanceof LiipImagineBundleStorageProviderInterface || $liipImagineBundleFilter === null) {
            return $storage->getThumbnail($this);
        }

        return $storage->getWebPathWithFilter($this, $liipImagineBundleFilter);
    }

    private function refreshAspectRatio(): void
    {
        if ($this->height === null || $this->width === null) {
            $this->aspectRatio = null;

            return;
        }

        $this->aspectRatio = AspectRatio::fromWidthAndHeight($this->width, $this->height);
    }

    /**
     * @ORM\PrePersist
     */
    public function onPrePersist()
    {
        $this->createdOn = $this->editedOn = new DateTime();

        $this->refreshAspectRatio();
    }

    /**
     * @ORM\PreUpdate
     */
    public function onPreUpdate()
    {
        $this->editedOn = new DateTime();

        $this->refreshAspectRatio();
    }
}
