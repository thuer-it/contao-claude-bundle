<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\File;
use Contao\FilesModel;
use Contao\Folder;
use Contao\StringUtil;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Legt Ordner an und verschiebt oder benennt Dateien und Ordner um.
 *
 * Verschoben wird mit Contaos eigenen Klassen (Folder::renameTo(),
 * File::renameTo()): Sie aktualisieren das DBAFS mit Dbafs::moveResource(),
 * die UUID bleibt also erhalten. Verweise über UUID (singleSRC, Download-
 * Listen u. a.) bleiben deshalb gültig; Verweise über den Pfad (Insert-Tags,
 * feste Links, Templates) tun das nicht, darauf weist die Antwort hin.
 *
 * Strenger als das Backend:
 *
 * - Nichts wird überschrieben, weder Datei noch Ordner.
 * - Die Endung einer Datei ändert sich nie (aus .jpg wird kein .php).
 * - Quelle und Ziel müssen in den Dateifreigaben des Benutzers liegen.
 * - Die Wurzel von files/ und die Freigaben selbst lassen sich nicht verschieben.
 * - Ein Ordner lässt sich nicht in sich selbst verschieben.
 * - Verbotene Namen (Endungen wie bei file_upload) werden abgewiesen.
 */
class FileOrganizer
{
	/**
	 * Endungen, die weder neu entstehen noch als Zielname dienen dürfen.
	 * Gleiche Liste wie beim Hochladen.
	 */
	private const FORBIDDEN_EXTENSIONS = array('php', 'phtml', 'phar', 'htm', 'html', 'js', 'htaccess');

	/**
	 * @param ContaoFramework               $framework     Für StringUtil und FilesModel
	 * @param Permissions                   $permissions   Modul- und Dateifreigaben
	 * @param AuthorizationCheckerInterface $authorization Recht zum Anlegen und Bearbeiten
	 * @param Settings                      $settings      Schalter für das Schreiben
	 * @param AuditLog                      $log           Protokoll
	 * @param string                        $projectDir    Projektverzeichnis
	 * @param string                        $uploadPath    Upload-Ordner, meist „files“
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Permissions $permissions,
		private readonly AuthorizationCheckerInterface $authorization,
		private readonly Settings $settings,
		private readonly AuditLog $log,
		private readonly string $projectDir,
		private readonly string $uploadPath,
	) {
	}

	/**
	 * Legt einen neuen Ordner an.
	 *
	 * Der übergeordnete Ordner muss es geben; es entsteht genau eine Ebene.
	 *
	 * @param string $path Pfad des neuen Ordners, etwa „files/presse/2027“
	 *
	 * @return array{path: string, uuid: string|null} Der neue Ordner
	 *
	 * @throws ToolCallException Wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function createFolder(string $path): array
	{
		$path = $this->normalize($path);

		try {
			$this->framework->initialize();
			$this->assertAllowed(ContaoCorePermissions::USER_CAN_UPLOAD_FILES, 'Dateien hochladen und Ordner anlegen');
			$this->assertInsideUploadPath($path);

			$parent = \dirname($path);
			$name = basename($path);

			$this->assertFolderName($name);

			if (!is_dir($this->projectDir.'/'.$parent)) {
				throw new ToolCallException(sprintf('Der übergeordnete Ordner %s existiert nicht. Es wird nur eine Ebene auf einmal angelegt.', $parent));
			}

			if (!$this->permissions->canReadPath($path)) {
				throw new ToolCallException(sprintf('%s liegt außerhalb deiner Dateifreigaben.', $path));
			}

			if (file_exists($this->projectDir.'/'.$path)) {
				throw new ToolCallException(sprintf('%s gibt es schon.', $path));
			}

			new Folder($path);

			$model = $this->framework->getAdapter(FilesModel::class)->findByPath($path);
		} catch (ToolCallException $exception) {
			$this->log->add('folder_create', 'tl_files', 0, array($path), false, $exception->getMessage());

			throw $exception;
		} catch (\Throwable $exception) {
			$this->log->add('folder_create', 'tl_files', 0, array($path), false, $exception->getMessage());

			throw new ToolCallException(sprintf('Der Ordner %s ließ sich nicht anlegen: %s', $path, $exception->getMessage()));
		}

		$this->log->add('folder_create', 'tl_files', (int) ($model?->id ?? 0), array($path), true);

		return array('path' => $path, 'uuid' => $model instanceof FilesModel ? StringUtil::binToUuid((string) $model->uuid) : null);
	}

	/**
	 * Verschiebt eine Datei oder einen Ordner oder benennt sie um.
	 *
	 * @param string $source Bisheriger Pfad
	 * @param string $target Neuer Pfad (vollständig, auch bei reinem Umbenennen)
	 *
	 * @return array{from: string, to: string, uuid: string|null, type: string, pathReferencesNote: string} Das Ergebnis
	 *
	 * @throws ToolCallException Wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function move(string $source, string $target): array
	{
		$source = $this->normalize($source);
		$target = $this->normalize($target);
		$id = 0;

		try {
			$this->framework->initialize();
			$this->assertAllowed(ContaoCorePermissions::USER_CAN_RENAME_FILE, 'Dateien und Ordner bearbeiten, kopieren und verschieben');
			$this->assertInsideUploadPath($source);
			$this->assertInsideUploadPath($target);

			if ($source === $target) {
				throw new ToolCallException('Quelle und Ziel sind gleich.');
			}

			$absoluteSource = $this->projectDir.'/'.$source;

			if (!file_exists($absoluteSource)) {
				throw new ToolCallException(sprintf('%s gibt es nicht.', $source));
			}

			$isFolder = is_dir($absoluteSource);

			if (is_link($absoluteSource)) {
				throw new ToolCallException('Symbolische Links werden nicht verschoben.');
			}

			if (!$this->permissions->canReadPath($source) || !$this->permissions->canReadPath($target)) {
				throw new ToolCallException('Quelle und Ziel müssen in deinen Dateifreigaben liegen.');
			}

			// Eine Freigabe selbst (und die Wurzel) bleibt stehen
			foreach ($this->permissions->getFileMounts() ?? array() as $mount) {
				if ($source === $mount) {
					throw new ToolCallException('Eine Dateifreigabe selbst lässt sich nicht verschieben oder umbenennen.');
				}
			}

			if ($isFolder && str_starts_with($target.'/', $source.'/')) {
				throw new ToolCallException('Ein Ordner lässt sich nicht in sich selbst verschieben.');
			}

			$targetParent = \dirname($target);

			if (!is_dir($this->projectDir.'/'.$targetParent)) {
				throw new ToolCallException(sprintf('Der Zielordner %s gibt es nicht (folder_create legt ihn an).', $targetParent));
			}

			if (file_exists($this->projectDir.'/'.$target)) {
				throw new ToolCallException(sprintf('%s gibt es schon. Es wird nichts überschrieben.', $target));
			}

			if ($isFolder) {
				$this->assertFolderName(basename($target));
			} else {
				$this->assertFileTarget($source, $target);
			}

			$model = $this->framework->getAdapter(FilesModel::class)->findByPath($source);

			if (!$model instanceof FilesModel) {
				throw new ToolCallException(sprintf('%s ist der Dateiverwaltung (DBAFS) nicht bekannt; ohne sie bliebe die UUID nicht erhalten. Bitte zuerst in Contao synchronisieren.', $source));
			}

			$id = (int) $model->id;

			if (!($isFolder ? new Folder($source) : new File($source))->renameTo($target)) {
				throw new ToolCallException('Contao hat nicht verschoben.');
			}

			$moved = $this->framework->getAdapter(FilesModel::class)->findByPath($target);
		} catch (ToolCallException $exception) {
			$this->log->add('file_move', 'tl_files', $id, array($source.' → '.$target), false, $exception->getMessage());

			throw $exception;
		} catch (\Throwable $exception) {
			$this->log->add('file_move', 'tl_files', $id, array($source.' → '.$target), false, $exception->getMessage());

			throw new ToolCallException(sprintf('%s ließ sich nicht verschieben: %s', $source, $exception->getMessage()));
		}

		$this->log->add('file_move', 'tl_files', $id, array($source.' → '.$target), true);

		return array(
			'from' => $source,
			'to' => $target,
			'uuid' => $moved instanceof FilesModel ? StringUtil::binToUuid((string) $moved->uuid) : null,
			'type' => $isFolder ? 'folder' : 'file',
			'pathReferencesNote' => 'Verweise über die UUID (Dateifelder, Bilder, Downloads) bleiben gültig. Fest eingetragene Pfade (Insert-Tags, Links in Texten, Templates) wurden nicht angepasst.',
		);
	}

	/**
	 * Vereinheitlicht einen Pfad: Schrägstriche, keine Randstriche.
	 *
	 * @param string $path Pfad wie übergeben
	 *
	 * @return string Der bereinigte Pfad
	 */
	private function normalize(string $path): string
	{
		return trim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * Prüft Schalter, Modul und Dateirecht.
	 *
	 * @param string $permission Konstante aus ContaoCorePermissions
	 * @param string $label      Name des Rechts für die Meldung
	 *
	 * @throws ToolCallException Wenn etwas davon fehlt
	 */
	private function assertAllowed(string $permission, string $label): void
	{
		if (!$this->settings->isWriteEnabled()) {
			throw new ToolCallException('Schreiben ist in dieser Installation für Claude nicht freigegeben.');
		}

		if (!$this->permissions->canAccessModule('files')) {
			throw new ToolCallException('Dein Backend-Zugang darf die Dateiverwaltung nicht öffnen.');
		}

		if (!$this->authorization->isGranted($permission)) {
			throw new ToolCallException(sprintf('Deinem Backend-Zugang fehlt das Recht „%s“.', $label));
		}
	}

	/**
	 * Prüft, dass ein Pfad unterhalb von files/ liegt und sauber ist.
	 *
	 * @param string $path Pfad relativ zum Projektverzeichnis
	 *
	 * @throws ToolCallException Bei Pfaden außerhalb, mit „..“ oder Nullbyte
	 */
	private function assertInsideUploadPath(string $path): void
	{
		if ('' === $path || !str_starts_with($path, $this->uploadPath.'/')) {
			throw new ToolCallException(sprintf('Nur Pfade unterhalb von %s/… sind erlaubt.', $this->uploadPath));
		}

		if (str_contains('/'.$path.'/', '/../') || str_contains('/'.$path.'/', '/./') || str_contains($path, "\0")) {
			throw new ToolCallException('Der Pfad ist ungültig.');
		}
	}

	/**
	 * Prüft den Namen eines Ordners.
	 *
	 * @param string $name Letzter Teil des Pfads
	 *
	 * @throws ToolCallException Bei leerem, versteckten oder von Contao anders bereinigtem Namen
	 */
	private function assertFolderName(string $name): void
	{
		$clean = (string) $this->framework->getAdapter(StringUtil::class)->sanitizeFileName($name);

		if ('' === $name || str_starts_with($name, '.') || $clean !== $name) {
			throw new ToolCallException(sprintf('Der Name „%s“ ist nicht zulässig. Erlaubt sind die Zeichen, die Contao auch beim Hochladen behält (vorgeschlagen: „%s“).', $name, $clean));
		}
	}

	/**
	 * Prüft den Zielnamen einer Datei: gleiche Endung, erlaubter Name.
	 *
	 * @param string $source Bisheriger Pfad
	 * @param string $target Neuer Pfad
	 *
	 * @throws ToolCallException Bei geänderter oder verbotener Endung
	 */
	private function assertFileTarget(string $source, string $target): void
	{
		$name = basename($target);
		$this->assertFolderName($name);

		$sourceExtension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
		$targetExtension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

		if ($sourceExtension !== $targetExtension) {
			throw new ToolCallException('Die Dateiendung darf sich nicht ändern.');
		}

		if (\in_array($targetExtension, self::FORBIDDEN_EXTENSIONS, true) || '' === pathinfo($name, PATHINFO_FILENAME)) {
			throw new ToolCallException(sprintf('Der Name %s ist für Claude nicht zulässig.', $name));
		}
	}
}
