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
 * file_move: eine Datei oder einen Ordner verschieben oder umbenennen.
 */
class FileMoveTool extends AbstractWriteTool
{
	/**
	 * @param FileOrganizer $files Verschiebt
	 */
	public function __construct(private readonly FileOrganizer $files)
	{
	}

	public function getName(): string
	{
		return 'file_move';
	}

	public function getTitle(): string
	{
		return 'Datei oder Ordner verschieben';
	}

	public function getDescription(): string
	{
		return 'Verschiebt oder benennt eine Datei oder einen Ordner unter files/ um (target ist immer der vollständige neue Pfad). Die UUID bleibt erhalten, Dateifelder und Bilder in Inhaltselementen zeigen also weiter auf die Datei; fest eingetragene Pfade in Texten oder Templates nicht. Nichts wird überschrieben, die Dateiendung bleibt gleich, Quelle und Ziel müssen in deinen Dateifreigaben liegen. Prüfe vorher die Hausregeln, ob vorhandene Dateien verschoben werden dürfen.';
	}

	public function getInputSchema(): array
	{
		return array(
			'type' => 'object',
			'properties' => array(
				'source' => array('type' => 'string', 'description' => 'Bisheriger Pfad, etwa „files/presse/bild.jpg“.'),
				'target' => array('type' => 'string', 'description' => 'Neuer vollständiger Pfad, etwa „files/presse/2026/siegerehrung.jpg“.'),
			),
			'required' => array('source', 'target'),
		);
	}

	/**
	 * @param string $source Bisheriger Pfad
	 * @param string $target Neuer Pfad
	 *
	 * @return array<string, mixed> action, from, to, uuid, type, pathReferencesNote
	 */
	/**
	 * Verschieben ändert Pfade, auf die Texte oder Templates verweisen können.
	 *
	 * @return bool true
	 */
	protected function isDestructive(): bool
	{
		return true;
	}

	public function __invoke(string $source, string $target): array
	{
		return array('action' => 'moved') + $this->files->move($source, $target);
	}
}
