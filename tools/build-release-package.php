<?php
/**
 * Build a cross-platform release-like plugin archive.
 *
 * @package WPAutoConnector
 */

declare(strict_types=1);

$project_root = dirname(__DIR__);
$build_root   = $project_root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'phase-1-7-2-fixed';
$package_root = $build_root . DIRECTORY_SEPARATOR . 'wepuu-auto-connector';
$zip_path     = $build_root . DIRECTORY_SEPARATOR . 'wepuu-auto-connector.zip';
$manifest_path = $build_root . DIRECTORY_SEPARATOR . 'manifest.json';

if (! class_exists('ZipArchive')) {
	fwrite(STDERR, "The PHP zip extension is required to build the release package.\n");
	exit(1);
}

if (is_dir($build_root)) {
	fwrite(STDERR, sprintf("Refusing to overwrite an existing build directory: %s\n", $build_root));
	exit(1);
}

/**
 * Stop the build with a clear error.
 */
function wp_auto_release_fail(string $message): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

/**
 * Create a directory and fail if it cannot be created.
 */
function wp_auto_release_mkdir(string $path): void {
	if (is_dir($path)) {
		return;
	}
	if (! mkdir($path, 0777, true)) {
		wp_auto_release_fail(sprintf('Could not create %s.', $path));
	}
}

/**
 * Copy a directory tree while preserving relative paths.
 */
function wp_auto_release_copy_tree(string $source, string $destination): void {
	if (! is_dir($source)) {
		wp_auto_release_fail(sprintf('Missing source directory: %s', $source));
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $item) {
		$relative = substr($item->getPathname(), strlen($source) + 1);
		$target   = $destination . DIRECTORY_SEPARATOR . $relative;

		if ($item->isDir()) {
			wp_auto_release_mkdir($target);
			continue;
		}

		wp_auto_release_mkdir(dirname($target));
		if (! copy($item->getPathname(), $target)) {
			wp_auto_release_fail(sprintf('Could not copy %s.', $item->getPathname()));
		}
	}
}

/**
 * Return the current Git commit for package provenance.
 */
function wp_auto_release_source_commit(string $project_root): string {
	$output    = array();
	$exit_code = 1;
	$command   = 'git -C ' . escapeshellarg($project_root) . ' rev-parse HEAD';
	exec($command, $output, $exit_code);

	if (0 !== $exit_code || empty($output) || ! preg_match('/^[0-9a-f]{40}$/', trim($output[0]))) {
		wp_auto_release_fail('Could not determine the source Git commit.');
	}

	return trim($output[0]);
}

/**
 * Add all files below a directory using POSIX archive paths.
 *
 * @return array<int, array{path:string, sha256:string, bytes:int}>
 */
function wp_auto_release_add_tree(ZipArchive $archive, string $source, string $archive_root, int $mtime): array {
	$files   = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ($iterator as $item) {
		if (! $item->isFile()) {
			continue;
		}

		$relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
		$entry    = rtrim($archive_root, '/') . '/' . $relative;

		if (false !== strpos($entry, '\\') || str_contains($entry, '/../') || str_starts_with($entry, '../')) {
			wp_auto_release_fail(sprintf('Unsafe archive path: %s', $entry));
		}

		$files[] = array(
			'absolute' => $item->getPathname(),
			'path'     => $entry,
			'sha256'   => strtolower(hash_file('sha256', $item->getPathname())),
			'bytes'    => $item->getSize(),
		);
	}

	usort(
		$files,
		static function (array $left, array $right): int {
			return strcmp($left['path'], $right['path']);
		}
	);

	$manifest_files = array();
	foreach ($files as $file) {
		if (! $archive->addFile($file['absolute'], $file['path'])) {
			wp_auto_release_fail(sprintf('Could not add %s to the archive.', $file['path']));
		}
		if (method_exists($archive, 'setMtimeName') && ! $archive->setMtimeName($file['path'], $mtime)) {
			wp_auto_release_fail(sprintf('Could not normalize the timestamp for %s.', $file['path']));
		}
		if (method_exists($archive, 'setCompressionName') && ! $archive->setCompressionName($file['path'], ZipArchive::CM_DEFLATE)) {
			wp_auto_release_fail(sprintf('Could not enable compression for %s.', $file['path']));
		}

		$manifest_files[] = array(
			'path'   => $file['path'],
			'sha256' => $file['sha256'],
			'bytes'  => $file['bytes'],
		);
	}

	return $manifest_files;
}

/**
 * Verify the archive has the expected cross-platform structure.
 */
function wp_auto_release_verify_archive(string $zip_path): int {
	$archive = new ZipArchive();
	if (true !== $archive->open($zip_path)) {
		wp_auto_release_fail('Could not reopen the generated ZIP for verification.');
	}

	$entries = array();
	for ($index = 0; $index < $archive->numFiles; $index++) {
		$name = (string) $archive->getNameIndex($index);
		if (false === $name || '' === $name) {
			wp_auto_release_fail('The archive contains an unreadable entry.');
		}
		if (false !== strpos($name, '\\')) {
			wp_auto_release_fail(sprintf('The archive contains a Windows path separator: %s', $name));
		}
		if (! str_starts_with($name, 'wepuu-auto-connector/')) {
			wp_auto_release_fail(sprintf('The archive entry is outside the package root: %s', $name));
		}
		if (preg_match('#^wepuu-auto-connector/(tests|docs|tools|build|dist|node_modules|\\.git)(/|$)#', $name)) {
			wp_auto_release_fail(sprintf('The archive contains a development path: %s', $name));
		}
		if (preg_match('/\\.(zip|log|tmp|bak)$/i', $name)) {
			wp_auto_release_fail(sprintf('The archive contains a forbidden generated file: %s', $name));
		}
		if ('wepuu-auto-connector/vendor/wordpress/mcp-adapter/mcp-adapter.php' === $name) {
			wp_auto_release_fail('The provider public Adapter entrypoint must not be packaged.');
		}
		$entries[] = $name;
	}
	$archive->close();

	$required = array(
		'wepuu-auto-connector/wepuu-auto-connector.php',
		'wepuu-auto-connector/uninstall.php',
		'wepuu-auto-connector/composer.json',
		'wepuu-auto-connector/composer.lock',
		'wepuu-auto-connector/vendor/autoload.php',
		'wepuu-auto-connector/vendor/wp-auto-mcp-runtime/autoload.php',
	);
	foreach ($required as $path) {
		if (! in_array($path, $entries, true)) {
			wp_auto_release_fail(sprintf('The archive is missing %s.', $path));
		}
	}

	return count($entries);
}

$root_files = array(
	'index.php',
	'uninstall.php',
	'wepuu-auto-connector.php',
	'LICENSE',
	'readme.txt',
	'composer.json',
	'composer.lock',
);

foreach ($root_files as $file) {
	if (! is_file($project_root . DIRECTORY_SEPARATOR . $file)) {
		wp_auto_release_fail(sprintf('Missing required project file: %s', $file));
	}
}

$runtime_manifest_path = $project_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'wp-auto-mcp-runtime' . DIRECTORY_SEPARATOR . 'manifest.json';
if (! is_file($runtime_manifest_path)) {
	wp_auto_release_fail('The private MCP runtime is missing. Run composer run build:mcp-runtime first.');
}
$runtime_manifest = json_decode((string) file_get_contents($runtime_manifest_path), true);
if (! is_array($runtime_manifest) || '0.6.1' !== ($runtime_manifest['packages']['wordpress/mcp-adapter'] ?? '') || '0.1.3' !== ($runtime_manifest['packages']['wordpress/php-mcp-schema'] ?? '')) {
	wp_auto_release_fail('The private MCP runtime does not match Adapter 0.6.1 and Schema 0.1.3.');
}

wp_auto_release_mkdir($package_root);
foreach ($root_files as $file) {
	if (! copy($project_root . DIRECTORY_SEPARATOR . $file, $package_root . DIRECTORY_SEPARATOR . $file)) {
		wp_auto_release_fail(sprintf('Could not copy %s.', $file));
	}
}
wp_auto_release_copy_tree($project_root . DIRECTORY_SEPARATOR . 'src', $package_root . DIRECTORY_SEPARATOR . 'src');

$composer = getenv('COMPOSER_BIN') ?: 'composer';
$command  = escapeshellcmd($composer) . ' install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction --working-dir=' . escapeshellarg($package_root);
$exit_code = 1;
passthru($command, $exit_code);
if (0 !== $exit_code) {
	wp_auto_release_fail('Production Composer install failed.');
}

wp_auto_release_copy_tree(
	$project_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'wp-auto-mcp-runtime',
	$package_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'wp-auto-mcp-runtime'
);

$public_adapter_entry = $package_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'mcp-adapter' . DIRECTORY_SEPARATOR . 'mcp-adapter.php';
if (is_file($public_adapter_entry) && ! unlink($public_adapter_entry)) {
	wp_auto_release_fail('Could not remove the provider public Adapter entrypoint.');
}

$mtime = 946684800;
if (false !== getenv('SOURCE_DATE_EPOCH') && ctype_digit((string) getenv('SOURCE_DATE_EPOCH'))) {
	$mtime = (int) getenv('SOURCE_DATE_EPOCH');
}

$archive = new ZipArchive();
if (true !== $archive->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
	wp_auto_release_fail('Could not create the release ZIP.');
}
$manifest_files = wp_auto_release_add_tree($archive, $package_root, 'wepuu-auto-connector', $mtime);
$archive->close();

$entry_count = wp_auto_release_verify_archive($zip_path);
$manifest   = array(
	'source_commit' => wp_auto_release_source_commit($project_root),
	'package_root'  => 'wepuu-auto-connector/',
	'private_runtime' => '0.6.1',
	'file_count'    => count($manifest_files),
	'archive_entries' => $entry_count,
	'files'         => $manifest_files,
	'zip_sha256'    => strtolower(hash_file('sha256', $zip_path)),
);
if (false === file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL)) {
	wp_auto_release_fail('Could not write the build manifest.');
}

fwrite(STDOUT, sprintf("Built cross-platform WePuu Auto Connector package: %s\n", $zip_path));
fwrite(STDOUT, sprintf("ZIP SHA-256: %s\n", $manifest['zip_sha256']));
fwrite(STDOUT, sprintf("Archive entries: %d\n", $entry_count));
