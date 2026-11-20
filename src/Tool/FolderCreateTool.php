<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Schachbulle\ContaoClaudeBundle\Write\FileOrganizer;

/**
 * folder_create: einen neuen Ordner in der Dateiverwaltung anlegen.
 */
class FolderCreateTool extends AbstractWriteTool
{
	/**
	 * @param FileOrganizer $files Legt den Ordner an
	 */
	public function __construct(private readonly FileOrganizer $files)
	{
	}

	public function getName(): string
	{
		return 'folder_create';
	}

	public function getTitle(): string
	{
		return 'Ordner anlegen';
	}

	public function getDescription(): string
	{
		return 'Legt einen neuen Ordner unter files/ an, genau eine Ebene unter einem vorhandenen Ordner und innerhalb deiner Dateifreigaben. Vorhandenes wird nie überschrieben. Der Name muss so bleiben, wie Contao ihn beim Hochladen bereinigen würde. Danach kann file_upload in den Ordner hochladen.';
	}

	public function getInputSchema(): array
	{
		return array(
			'type' => 'object',
			'properties' => array(
				'path' => array('type' => 'string', 'description' => 'Pfad des neuen Ordners, etwa „files/presse/2027“.'),
			),
			'required' => array('path'),
		);
	}

	/**
	 * @param string $path Pfad des neuen Ordners
	 *
	 * @return array<string, mixed> action, path, uuid
	 */
	public function __invoke(string $path): array
	{
		return array('action' => 'created') + $this->files->createFolder($path);
	}
}
