<?php declare(strict_types = 1);

namespace PHPStan\Analyser\ResultCache;

use Nette\DI\Definitions\Statement;
use Nette\Neon\Neon;
use PHPStan\Analyser\AnalyserResult;
use PHPStan\Analyser\Error;
use PHPStan\Command\Output;
use PHPStan\Dependency\ExportedNode;
use PHPStan\Dependency\ExportedNodeFetcher;
use PHPStan\File\FileFinder;
use PHPStan\File\FileReader;
use PHPStan\File\FileWriter;
use PHPStan\Reflection\ReflectionProvider;
use function array_fill_keys;
use function array_key_exists;

class ResultCacheManager
{

	private const CACHE_VERSION = 'v9-project-extensions';

	private ExportedNodeFetcher $exportedNodeFetcher;

	private FileFinder $scanFileFinder;

	private ReflectionProvider $reflectionProvider;

	private string $cacheFilePath;

	private string $tempResultCachePath;

	/** @var string[] */
	private array $analysedPaths;

	/** @var string[] */
	private array $composerAutoloaderProjectPaths;

	/** @var string[] */
	private array $stubFiles;

	private string $usedLevel;

	private ?string $cliAutoloadFile;

	/** @var string[] */
	private array $bootstrapFiles;

	/** @var string[] */
	private array $scanFiles;

	/** @var string[] */
	private array $scanDirectories;

	/** @var array<string, string> */
	private array $fileHashes = [];

	/** @var array<string, string> */
	private array $fileReplacements = [];

	/** @var array<string, true> */
	private array $alreadyProcessed = [];

	private bool $checkDependenciesOfProjectExtensionFiles;

	/**
	 * @param ExportedNodeFetcher $exportedNodeFetcher
	 * @param FileFinder $scanFileFinder
	 * @param ReflectionProvider $reflectionProvider
	 * @param string $cacheFilePath
	 * @param string $tempResultCachePath
	 * @param string[] $analysedPaths
	 * @param string[] $composerAutoloaderProjectPaths
	 * @param string[] $stubFiles
	 * @param string $usedLevel
	 * @param string|null $cliAutoloadFile
	 * @param string[] $bootstrapFiles
	 * @param string[] $scanFiles
	 * @param string[] $scanDirectories
	 * @param array<string, string> $fileReplacements
	 */
	public function __construct(
		ExportedNodeFetcher $exportedNodeFetcher,
		FileFinder $scanFileFinder,
		ReflectionProvider $reflectionProvider,
		string $cacheFilePath,
		string $tempResultCachePath,
		array $analysedPaths,
		array $composerAutoloaderProjectPaths,
		array $stubFiles,
		string $usedLevel,
		?string $cliAutoloadFile,
		array $bootstrapFiles,
		array $scanFiles,
		array $scanDirectories,
		array $fileReplacements,
		bool $checkDependenciesOfProjectExtensionFiles
	)
	{
		$this->exportedNodeFetcher = $exportedNodeFetcher;
		$this->scanFileFinder = $scanFileFinder;
		$this->reflectionProvider = $reflectionProvider;
		$this->cacheFilePath = $cacheFilePath;
		$this->tempResultCachePath = $tempResultCachePath;
		$this->analysedPaths = $analysedPaths;
		$this->composerAutoloaderProjectPaths = $composerAutoloaderProjectPaths;
		$this->stubFiles = $stubFiles;
		$this->usedLevel = $usedLevel;
		$this->cliAutoloadFile = $cliAutoloadFile;
		$this->bootstrapFiles = $bootstrapFiles;
		$this->scanFiles = $scanFiles;
		$this->scanDirectories = $scanDirectories;
		$this->fileReplacements = $fileReplacements;
		$this->checkDependenciesOfProjectExtensionFiles = $checkDependenciesOfProjectExtensionFiles;
	}

	/**
	 * @param string[] $allAnalysedFiles
	 * @param mixed[]|null $projectConfigArray
	 * @param bool $debug
	 * @return ResultCache
	 */
	public function restore(array $allAnalysedFiles, bool $debug, bool $onlyFiles, ?array $projectConfigArray, Output $output, ?string $resultCacheName = null): ResultCache
	{
		if ($debug) {
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because of debug mode.');
			}
			return new ResultCache($allAnalysedFiles, true, time(), $this->getMeta($allAnalysedFiles, $projectConfigArray), [], [], []);
		}
		if ($onlyFiles) {
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because only files were passed as analysed paths.');
			}
			return new ResultCache($allAnalysedFiles, true, time(), $this->getMeta($allAnalysedFiles, $projectConfigArray), [], [], []);
		}

		$cacheFilePath = $this->cacheFilePath;
		if ($resultCacheName !== null) {
			$tmpCacheFile = $this->tempResultCachePath . '/' . $resultCacheName . '.php';
			if (is_file($tmpCacheFile)) {
				$cacheFilePath = $tmpCacheFile;
			}
		}

		if (!is_file($cacheFilePath)) {
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because the cache file does not exist.');
			}
			return new ResultCache($allAnalysedFiles, true, time(), $this->getMeta($allAnalysedFiles, $projectConfigArray), [], [], []);
		}

		try {
			$data = require $cacheFilePath;
		} catch (\Throwable $e) {
			if ($output->isDebug()) {
				$output->writeLineFormatted(sprintf('Result cache not used because an error occurred while loading the cache file: %s', $e->getMessage()));
			}

			@unlink($cacheFilePath);

			return new ResultCache($allAnalysedFiles, true, time(), $this->getMeta($allAnalysedFiles, $projectConfigArray), [], [], []);
		}

		if (!is_array($data)) {
			@unlink($cacheFilePath);
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because the cache file is corrupted.');
			}

			return new ResultCache($allAnalysedFiles, true, time(), $this->getMeta($allAnalysedFiles, $projectConfigArray), [], [], []);
		}

		$meta = $this->getMeta($allAnalysedFiles, $projectConfigArray);
		if ($this->isMetaDifferent($data['meta'], $meta)) {
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because the metadata do not match.');
			}
			return new ResultCache($allAnalysedFiles, true, time(), $meta, [], [], []);
		}

		if (time() - $data['lastFullAnalysisTime'] >= 60 * 60 * 24 * 7) {
			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache not used because it\'s more than 7 days since last full analysis.');
			}
			// run full analysis if the result cache is older than 7 days
			return new ResultCache($allAnalysedFiles, true, time(), $meta, [], [], []);
		}

		foreach ($data['projectExtensionFiles'] as $extensionFile => $fileHash) {
			if (!is_file($extensionFile)) {
				if ($output->isDebug()) {
					$output->writeLineFormatted(sprintf('Result cache not used because extension file %s was not found.', $extensionFile));
				}
				return new ResultCache($allAnalysedFiles, true, time(), $meta, [], [], []);
			}

			if ($this->getFileHash($extensionFile) === $fileHash) {
				continue;
			}

			if ($output->isDebug()) {
				$output->writeLineFormatted(sprintf('Result cache not used because extension file %s hash does not match.', $extensionFile));
			}

			return new ResultCache($allAnalysedFiles, true, time(), $meta, [], [], []);
		}

		$invertedDependencies = $data['dependencies'];
		$deletedFiles = array_fill_keys(array_keys($invertedDependencies), true);
		$filesToAnalyse = [];
		$invertedDependenciesToReturn = [];
		$errors = $data['errorsCallback']();
		$exportedNodes = $data['exportedNodesCallback']();
		$filteredErrors = [];
		$filteredExportedNodes = [];
		$newFileAppeared = false;
		foreach ($allAnalysedFiles as $analysedFile) {
			if (array_key_exists($analysedFile, $errors)) {
				$filteredErrors[$analysedFile] = $errors[$analysedFile];
			}
			if (array_key_exists($analysedFile, $exportedNodes)) {
				$filteredExportedNodes[$analysedFile] = $exportedNodes[$analysedFile];
			}
			if (!array_key_exists($analysedFile, $invertedDependencies)) {
				// new file
				$filesToAnalyse[] = $analysedFile;
				$newFileAppeared = true;
				continue;
			}

			unset($deletedFiles[$analysedFile]);

			$analysedFileData = $invertedDependencies[$analysedFile];
			$cachedFileHash = $analysedFileData['fileHash'];
			$dependentFiles = $analysedFileData['dependentFiles'];
			$invertedDependenciesToReturn[$analysedFile] = $dependentFiles;
			$currentFileHash = $this->getFileHash($analysedFile);

			if ($cachedFileHash === $currentFileHash) {
				continue;
			}

			$filesToAnalyse[] = $analysedFile;
			if (!array_key_exists($analysedFile, $filteredExportedNodes)) {
				continue;
			}

			$cachedFileExportedNodes = $filteredExportedNodes[$analysedFile];
			if (count($dependentFiles) === 0) {
				continue;
			}
			if (!$this->exportedNodesChanged($analysedFile, $cachedFileExportedNodes)) {
				continue;
			}

			foreach ($dependentFiles as $dependentFile) {
				if (!is_file($dependentFile)) {
					continue;
				}
				$filesToAnalyse[] = $dependentFile;
			}
		}

		foreach (array_keys($deletedFiles) as $deletedFile) {
			if (!array_key_exists($deletedFile, $invertedDependencies)) {
				continue;
			}

			$deletedFileData = $invertedDependencies[$deletedFile];
			$dependentFiles = $deletedFileData['dependentFiles'];
			foreach ($dependentFiles as $dependentFile) {
				if (!is_file($dependentFile)) {
					continue;
				}
				$filesToAnalyse[] = $dependentFile;
			}
		}

		if ($newFileAppeared) {
			foreach (array_keys($filteredErrors) as $fileWithError) {
				$filesToAnalyse[] = $fileWithError;
			}
		}

		return new ResultCache(array_unique($filesToAnalyse), false, $data['lastFullAnalysisTime'], $meta, $filteredErrors, $invertedDependenciesToReturn, $filteredExportedNodes);
	}

	/**
	 * @param mixed[] $cachedMeta
	 * @param mixed[] $currentMeta
	 * @return bool
	 */
	private function isMetaDifferent(array $cachedMeta, array $currentMeta): bool
	{
		$projectConfig = $currentMeta['projectConfig'];
		if ($projectConfig !== null) {
			$currentMeta['projectConfig'] = Neon::encode($currentMeta['projectConfig']);
		}

		return $cachedMeta !== $currentMeta;
	}

	/**
	 * @param string $analysedFile
	 * @param array<int, ExportedNode> $cachedFileExportedNodes
	 * @return bool
	 */
	private function exportedNodesChanged(string $analysedFile, array $cachedFileExportedNodes): bool
	{
		if (array_key_exists($analysedFile, $this->fileReplacements)) {
			$analysedFile = $this->fileReplacements[$analysedFile];
		}
		$fileExportedNodes = $this->exportedNodeFetcher->fetchNodes($analysedFile);
		if (count($fileExportedNodes) !== count($cachedFileExportedNodes)) {
			return true;
		}

		foreach ($fileExportedNodes as $i => $fileExportedNode) {
			$cachedExportedNode = $cachedFileExportedNodes[$i];
			if (!$cachedExportedNode->equals($fileExportedNode)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param AnalyserResult $analyserResult
	 * @param ResultCache $resultCache
	 * @param bool|string $save
	 * @return ResultCacheProcessResult
	 */
	public function process(AnalyserResult $analyserResult, ResultCache $resultCache, Output $output, bool $onlyFiles, $save): ResultCacheProcessResult
	{
		$internalErrors = $analyserResult->getInternalErrors();
		$freshErrorsByFile = [];
		foreach ($analyserResult->getErrors() as $error) {
			$freshErrorsByFile[$error->getFilePath()][] = $error;
		}

		$meta = $resultCache->getMeta();
		$doSave = function (array $errorsByFile, ?array $dependencies, array $exportedNodes, ?string $resultCacheName) use ($internalErrors, $resultCache, $output, $onlyFiles, $meta): bool {
			if ($onlyFiles) {
				if ($output->isDebug()) {
					$output->writeLineFormatted('Result cache was not saved because only files were passed as analysed paths.');
				}
				return false;
			}
			if ($dependencies === null) {
				if ($output->isDebug()) {
					$output->writeLineFormatted('Result cache was not saved because of error in dependencies.');
				}
				return false;
			}

			if (count($internalErrors) > 0) {
				if ($output->isDebug()) {
					$output->writeLineFormatted('Result cache was not saved because of internal errors.');
				}
				return false;
			}

			foreach ($errorsByFile as $errors) {
				foreach ($errors as $error) {
					if (!$error->hasNonIgnorableException()) {
						continue;
					}

					if ($output->isDebug()) {
						$output->writeLineFormatted(sprintf('Result cache was not saved because of non-ignorable exception: %s', $error->getMessage()));
					}

					return false;
				}
			}

			$this->save($resultCache->getLastFullAnalysisTime(), $resultCacheName, $errorsByFile, $dependencies, $exportedNodes, $meta);

			if ($output->isDebug()) {
				$output->writeLineFormatted('Result cache is saved.');
			}

			return true;
		};

		if ($resultCache->isFullAnalysis()) {
			$saved = false;
			if ($save !== false) {
				$saved = $doSave($freshErrorsByFile, $analyserResult->getDependencies(), $analyserResult->getExportedNodes(), is_string($save) ? $save : null);
			} else {
				if ($output->isDebug()) {
					$output->writeLineFormatted('Result cache was not saved because it was not requested.');
				}
			}

			return new ResultCacheProcessResult($analyserResult, $saved);
		}

		$errorsByFile = $this->mergeErrors($resultCache, $freshErrorsByFile);
		$dependencies = $this->mergeDependencies($resultCache, $analyserResult->getDependencies());
		$exportedNodes = $this->mergeExportedNodes($resultCache, $analyserResult->getExportedNodes());

		$saved = false;
		if ($save !== false) {
			$saved = $doSave($errorsByFile, $dependencies, $exportedNodes, is_string($save) ? $save : null);
		}

		$flatErrors = [];
		foreach ($errorsByFile as $fileErrors) {
			foreach ($fileErrors as $fileError) {
				$flatErrors[] = $fileError;
			}
		}

		return new ResultCacheProcessResult(new AnalyserResult(
			$flatErrors,
			$internalErrors,
			$dependencies,
			$exportedNodes,
			$analyserResult->hasReachedInternalErrorsCountLimit()
		), $saved);
	}

	/**
	 * @param ResultCache $resultCache
	 * @param array<string, array<Error>> $freshErrorsByFile
	 * @return array<string, array<Error>>
	 */
	private function mergeErrors(ResultCache $resultCache, array $freshErrorsByFile): array
	{
		$errorsByFile = $resultCache->getErrors();
		foreach ($resultCache->getFilesToAnalyse() as $file) {
			if (!array_key_exists($file, $freshErrorsByFile)) {
				unset($errorsByFile[$file]);
				continue;
			}
			$errorsByFile[$file] = $freshErrorsByFile[$file];
		}

		return $errorsByFile;
	}

	/**
	 * @param ResultCache $resultCache
	 * @param array<string, array<string>>|null $freshDependencies
	 * @return array<string, array<string>>|null
	 */
	private function mergeDependencies(ResultCache $resultCache, ?array $freshDependencies): ?array
	{
		if ($freshDependencies === null) {
			return null;
		}

		$cachedDependencies = [];
		$resultCacheDependencies = $resultCache->getDependencies();
		$filesNoOneIsDependingOn = array_fill_keys(array_keys($resultCacheDependencies), true);
		foreach ($resultCacheDependencies as $file => $filesDependingOnFile) {
			foreach ($filesDependingOnFile as $fileDependingOnFile) {
				$cachedDependencies[$fileDependingOnFile][] = $file;
				unset($filesNoOneIsDependingOn[$fileDependingOnFile]);
			}
		}

		foreach (array_keys($filesNoOneIsDependingOn) as $file) {
			if (array_key_exists($file, $cachedDependencies)) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			$cachedDependencies[$file] = [];
		}

		$newDependencies = $cachedDependencies;
		foreach ($resultCache->getFilesToAnalyse() as $file) {
			if (!array_key_exists($file, $freshDependencies)) {
				unset($newDependencies[$file]);
				continue;
			}

			$newDependencies[$file] = $freshDependencies[$file];
		}

		return $newDependencies;
	}

	/**
	 * @param ResultCache $resultCache
	 * @param array<string, array<ExportedNode>> $freshExportedNodes
	 * @return array<string, array<ExportedNode>>
	 */
	private function mergeExportedNodes(ResultCache $resultCache, array $freshExportedNodes): array
	{
		$newExportedNodes = $resultCache->getExportedNodes();
		foreach ($resultCache->getFilesToAnalyse() as $file) {
			if (!array_key_exists($file, $freshExportedNodes)) {
				unset($newExportedNodes[$file]);
				continue;
			}

			$newExportedNodes[$file] = $freshExportedNodes[$file];
		}

		return $newExportedNodes;
	}

	/**
	 * @param int $lastFullAnalysisTime
	 * @param string|null $resultCacheName
	 * @param array<string, array<Error>> $errors
	 * @param array<string, array<string>> $dependencies
	 * @param array<string, array<ExportedNode>> $exportedNodes
	 * @param mixed[] $meta
	 */
	private function save(
		int $lastFullAnalysisTime,
		?string $resultCacheName,
		array $errors,
		array $dependencies,
		array $exportedNodes,
		array $meta
	): void
	{
		$invertedDependencies = [];
		$filesNoOneIsDependingOn = array_fill_keys(array_keys($dependencies), true);
		foreach ($dependencies as $file => $fileDependencies) {
			foreach ($fileDependencies as $fileDep) {
				if (!array_key_exists($fileDep, $invertedDependencies)) {
					$invertedDependencies[$fileDep] = [
						'fileHash' => $this->getFileHash($fileDep),
						'dependentFiles' => [],
					];
					unset($filesNoOneIsDependingOn[$fileDep]);
				}
				$invertedDependencies[$fileDep]['dependentFiles'][] = $file;
			}
		}

		foreach (array_keys($filesNoOneIsDependingOn) as $file) {
			if (array_key_exists($file, $invertedDependencies)) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			if (!is_file($file)) {
				continue;
			}

			$invertedDependencies[$file] = [
				'fileHash' => $this->getFileHash($file),
				'dependentFiles' => [],
			];
		}

		ksort($errors);
		ksort($invertedDependencies);

		foreach ($invertedDependencies as $file => $fileData) {
			$dependentFiles = $fileData['dependentFiles'];
			sort($dependentFiles);
			$invertedDependencies[$file]['dependentFiles'] = $dependentFiles;
		}

		$template = <<<'php'
<?php declare(strict_types = 1);

return [
	'lastFullAnalysisTime' => %s,
	'meta' => %s,
	'projectExtensionFiles' => %s,
	'errorsCallback' => static function (): array { return %s; },
	'dependencies' => %s,
	'exportedNodesCallback' => static function (): array { return %s; },
];
php;

		ksort($exportedNodes);

		$file = $this->cacheFilePath;
		if ($resultCacheName !== null) {
			$file = $this->tempResultCachePath . '/' . $resultCacheName . '.php';
		}

		$projectConfigArray = $meta['projectConfig'];
		if ($projectConfigArray !== null) {
			$meta['projectConfig'] = Neon::encode($projectConfigArray);
		}

		FileWriter::write(
			$file,
			sprintf(
				$template,
				var_export($lastFullAnalysisTime, true),
				var_export($meta, true),
				var_export($this->getProjectExtensionFiles($projectConfigArray, $dependencies), true),
				var_export($errors, true),
				var_export($invertedDependencies, true),
				var_export($exportedNodes, true)
			)
		);
	}

	/**
	 * @param mixed[]|null $projectConfig
	 * @param array<string, mixed> $dependencies
	 * @return array<string, string>
	 */
	private function getProjectExtensionFiles(?array $projectConfig, array $dependencies): array
	{
		$this->alreadyProcessed = [];
		$projectExtensionFiles = [];
		if ($projectConfig !== null) {
			$services = array_merge(
				$projectConfig['services'] ?? [],
				$projectConfig['rules'] ?? []
			);
			foreach ($services as $service) {
				$classes = $this->getClassesFromConfigDefinition($service);
				if (is_array($service)) {
					foreach (['class', 'factory', 'implement'] as $key) {
						if (!isset($service[$key])) {
							continue;
						}

						$classes = array_merge($classes, $this->getClassesFromConfigDefinition($service[$key]));
					}
				}

				foreach (array_unique($classes) as $class) {
					if (!$this->reflectionProvider->hasClass($class)) {
						continue;
					}

					$classReflection = $this->reflectionProvider->getClass($class);
					$fileName = $classReflection->getFileName();
					if ($fileName === false) {
						continue;
					}

					$allServiceFiles = $this->getAllDependencies($fileName, $dependencies);
					foreach ($allServiceFiles as $serviceFile) {
						if (array_key_exists($serviceFile, $projectExtensionFiles)) {
							continue;
						}

						$projectExtensionFiles[$serviceFile] = $this->getFileHash($serviceFile);
					}
				}
			}
		}

		return $projectExtensionFiles;
	}

	/**
	 * @param mixed $definition
	 * @return string[]
	 */
	private function getClassesFromConfigDefinition($definition): array
	{
		if (is_string($definition)) {
			return [$definition];
		}

		if ($definition instanceof Statement) {
			$entity = $definition->entity;
			if (is_string($entity)) {
				return [$entity];
			} elseif (is_array($entity) && isset($entity[0]) && is_string($entity[0])) {
				return [$entity[0]];
			}
		}

		return [];
	}

	/**
	 * @param string $fileName
	 * @param array<string, array<int, string>> $dependencies
	 * @return array<int, string>
	 */
	private function getAllDependencies(string $fileName, array $dependencies): array
	{
		if (!array_key_exists($fileName, $dependencies)) {
			return [];
		}

		if (array_key_exists($fileName, $this->alreadyProcessed)) {
			return [];
		}

		$this->alreadyProcessed[$fileName] = true;

		$files = [$fileName];

		if ($this->checkDependenciesOfProjectExtensionFiles) {
			foreach ($dependencies[$fileName] as $fileDep) {
				foreach ($this->getAllDependencies($fileDep, $dependencies) as $fileDep2) {
					$files[] = $fileDep2;
				}
			}
		}

		return $files;
	}

	/**
	 * @param string[] $allAnalysedFiles
	 * @param mixed[]|null $projectConfigArray
	 * @return mixed[]
	 */
	private function getMeta(array $allAnalysedFiles, ?array $projectConfigArray): array
	{
		$extensions = array_values(array_filter(get_loaded_extensions(), static function (string $extension): bool {
			return $extension !== 'xdebug';
		}));
		sort($extensions);

		if ($projectConfigArray !== null) {
			unset($projectConfigArray['parameters']['ignoreErrors']);
			unset($projectConfigArray['parameters']['tipsOfTheDay']);
			unset($projectConfigArray['parameters']['parallel']);
			unset($projectConfigArray['parameters']['internalErrorsCountLimit']);
			unset($projectConfigArray['parameters']['cache']);
			unset($projectConfigArray['parameters']['reportUnmatchedIgnoredErrors']);
			unset($projectConfigArray['parameters']['memoryLimitFile']);
			unset($projectConfigArray['parametersSchema']);
		}

		return [
			'cacheVersion' => self::CACHE_VERSION,
			'phpstanVersion' => $this->getPhpStanVersion(),
			'phpVersion' => PHP_VERSION_ID,
			'projectConfig' => $projectConfigArray,
			'analysedPaths' => $this->analysedPaths,
			'scannedFiles' => $this->getScannedFiles($allAnalysedFiles),
			'composerLocks' => $this->getComposerLocks(),
			'composerInstalled' => $this->getComposerInstalled(),
			'executedFilesHashes' => $this->getExecutedFileHashes(),
			'phpExtensions' => $extensions,
			'stubFiles' => $this->getStubFiles(),
			'level' => $this->usedLevel,
		];
	}

	private function getFileHash(string $path): string
	{
		if (array_key_exists($path, $this->fileReplacements)) {
			$path = $this->fileReplacements[$path];
		}
		if (array_key_exists($path, $this->fileHashes)) {
			return $this->fileHashes[$path];
		}

		$contents = FileReader::read($path);
		$contents = str_replace("\r\n", "\n", $contents);

		$hash = sha1($contents);
		$this->fileHashes[$path] = $hash;

		return $hash;
	}

	/**
	 * @param string[] $allAnalysedFiles
	 * @return array<string, string>
	 */
	private function getScannedFiles(array $allAnalysedFiles): array
	{
		$scannedFiles = $this->scanFiles;
		foreach ($this->scanFileFinder->findFiles($this->scanDirectories)->getFiles() as $file) {
			$scannedFiles[] = $file;
		}

		$scannedFiles = array_unique($scannedFiles);

		$hashes = [];
		foreach (array_diff($scannedFiles, $allAnalysedFiles) as $file) {
			$hashes[$file] = $this->getFileHash($file);
		}

		ksort($hashes);

		return $hashes;
	}

	/**
	 * @return array<string, string>
	 */
	private function getExecutedFileHashes(): array
	{
		$hashes = [];
		if ($this->cliAutoloadFile !== null) {
			$hashes[$this->cliAutoloadFile] = $this->getFileHash($this->cliAutoloadFile);
		}

		foreach ($this->bootstrapFiles as $bootstrapFile) {
			$hashes[$bootstrapFile] = $this->getFileHash($bootstrapFile);
		}

		ksort($hashes);

		return $hashes;
	}

	private function getPhpStanVersion(): string
	{
		try {
			return \Jean85\PrettyVersions::getVersion('phpstan/phpstan')->getPrettyVersion();
		} catch (\OutOfBoundsException $e) {
			return 'Version unknown';
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function getComposerLocks(): array
	{
		$locks = [];
		foreach ($this->composerAutoloaderProjectPaths as $autoloadPath) {
			$lockPath = $autoloadPath . '/composer.lock';
			if (!is_file($lockPath)) {
				continue;
			}

			$locks[$lockPath] = $this->getFileHash($lockPath);
		}

		return $locks;
	}

	/**
	 * @return array<string, string>
	 */
	private function getComposerInstalled(): array
	{
		$data = [];
		foreach ($this->composerAutoloaderProjectPaths as $autoloadPath) {
			$filePath = $autoloadPath . '/vendor/composer/installed.php';
			if (!is_file($filePath)) {
				continue;
			}

			$installed = require $filePath;
			$rootName = $installed['root']['name'];
			unset($installed['root']);
			unset($installed['versions'][$rootName]);

			$data[$filePath] = $installed;
		}

		return $data;
	}

	/**
	 * @return array<string, string>
	 */
	private function getStubFiles(): array
	{
		$stubFiles = [];
		foreach ($this->stubFiles as $stubFile) {
			$stubFiles[$stubFile] = $this->getFileHash($stubFile);
		}

		ksort($stubFiles);

		return $stubFiles;
	}

}
