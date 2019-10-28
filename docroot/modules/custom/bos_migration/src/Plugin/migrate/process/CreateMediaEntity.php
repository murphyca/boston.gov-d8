<?php

namespace Drupal\bos_migration\Plugin\migrate\process;

/*
 * COB NOTE:
 * In this boston.gov implementation, this class/plugin is added by
 *   bos_migration->bos_migration_migration_plugins_alter()
 * which adds this plugin to the process of 'text_long', 'text_with_summary'
 * fields.
 */

use Drupal\bos_migration\MigrationFixes;
use Drupal\file\Entity\File;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\Plugin\Migration;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Component\Utility\Html;
use Drupal\media\MediaInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\media\Entity\Media;
use Drupal\Component\Serialization\Json;
use Exception;

/**
 * Replace local image and link tags with entity embeds.
 *
 * @MigrateProcessPlugin(
 *   id = "create_media_entity"
 * )
 */
class CreateMediaEntity extends ProcessPluginBase {
  use \Drupal\bos_migration\FilesystemReorganizationTrait;
  use \Drupal\bos_migration\MediaEntityTrait;

  protected $row;
  protected $migrate_executable;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $this->row = $row;
    $this->migrate_executable = $migrate_executable;

    if ($this->configuration["value_type"] == "fid" || is_numeric($value)) {
      $this->createMediaFromId($value);
    }
    elseif ($this->configuration["value_type"] == "uri" || is_string($value)) {
      $this->createMediaFromUri($value);
    }
    return $value;
  }

  /**
   * Creates a media entity from a file_id (fid).
   *
   * @param int $fid
   *   The file id.
   *
   * @throws \Drupal\migrate\MigrateSkipProcessException
   */
  public function createMediaFromId(int $fid) {
    if (NULL != ($file = File::load($fid))) {
      // Check config for media library flag, default to FALSE.
      $in_library = !empty($this->configuration["media_library"]);
      $filename = $file->getFilename();
      $filename = $this->cleanFilename($filename);
      $file_author = $file->get("uid")->target_id;
      $src = $file->get("uri")->value;
      $targetBundle = reset($this->resolveFileTypeArray($src));
      $this->createMediaEntity($targetBundle, $this->row->getSource()["fid"], $filename, $file_author, $in_library);
    }
    else {
      $this->migrate_executable->saveMessage("File entity $fid not found.");
      throw new MigrateSkipProcessException("File entity $fid not found.");
    }
  }

  /**
   * Creates a media entity from a source uri.
   *
   * @param string $src
   *   The file src.
   *
   * @throws \Drupal\migrate\MigrateSkipProcessException
   */
  public function createMediaFromUri(string $src) {
    $targetBundle = reset($this->resolveFileTypeArray($src));

    if ($targetBundle == "link") {
      $this->migrate_executable->saveMessage('Only image and document bundles are supported.');
      throw new MigrateSkipProcessException('Only image and document bundles are supported.');
    }

    // Check config for media library flag, default to FALSE.
    $in_library = !empty($this->configuration["media_library"]);
    // Try to get the author from the underlying file object
    $file_author = NULL;
    if (isset($this->row->getSource()["uid"])) {
      $file_author = $this->row->getSource()["uid"];
    }
    if (isset($this->row->getSource()["filename"])) {
      $filename = $this->row->getSource()["filename"];
    }
    else {
      $filename = $this->cleanFilename($src);
    }
    $this->createMediaEntity($targetBundle, $this->row->getSource()["fid"], $filename, $file_author, $in_library);
  }

  public function getMediaEntity() {

  }




}
