<?php declare(strict_types = 1);

namespace PHPStan\Reflection\BetterReflection\SourceLocator;

use PHPStan\BetterReflection\Identifier\Identifier;
use PHPStan\BetterReflection\Identifier\IdentifierType;
use PHPStan\BetterReflection\Reflection\Reflection;
use PHPStan\BetterReflection\Reflector\Reflector;
use PHPStan\BetterReflection\SourceLocator\Ast\Strategy\NodeToReflection;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use function array_key_exists;

class OptimizedDirectorySourceLocator implements SourceLocator
{

	private \PHPStan\Reflection\BetterReflection\SourceLocator\FileNodesFetcher $fileNodesFetcher;

	/** @var string[] */
	private array $files;

	/** @var array<string, string>|null */
	private ?array $classToFile = null;

	/** @var array<string, array<int, string>>|null */
	private ?array $functionToFiles = null;

	/** @var array<string, FetchedNode<\PhpParser\Node\Stmt\ClassLike>> */
	private array $classNodes = [];

	/** @var array<string, FetchedNode<\PhpParser\Node\Stmt\Function_>> */
	private array $functionNodes = [];

	/** @var array<string, \PHPStan\BetterReflection\SourceLocator\Located\LocatedSource> */
	private array $locatedSourcesByFile = [];

	/**
	 * @param FileNodesFetcher $fileNodesFetcher
	 * @param string[] $files
	 */
	public function __construct(
		FileNodesFetcher $fileNodesFetcher,
		array $files
	)
	{
		$this->fileNodesFetcher = $fileNodesFetcher;
		$this->files = $files;
	}

	public function locateIdentifier(Reflector $reflector, Identifier $identifier): ?Reflection
	{
		if ($identifier->isClass()) {
			$className = strtolower($identifier->getName());
			if (array_key_exists($className, $this->classNodes)) {
				return $this->nodeToReflection($reflector, $this->classNodes[$className]);
			}

			$file = $this->findFileByClass($className);
			if ($file === null) {
				return null;
			}

			$fetchedNodesResult = $this->fileNodesFetcher->fetchNodes($file);
			$locatedSource = $fetchedNodesResult->getLocatedSource();
			$this->locatedSourcesByFile[$file] = $locatedSource;
			foreach ($fetchedNodesResult->getClassNodes() as $identifierName => $fetchedClassNodes) {
				foreach ($fetchedClassNodes as $fetchedClassNode) {
					$this->classNodes[$identifierName] = $fetchedClassNode;
					break;
				}
			}

			if (!array_key_exists($className, $this->classNodes)) {
				return null;
			}

			return $this->nodeToReflection($reflector, $this->classNodes[$className]);
		}

		if ($identifier->isFunction()) {
			$functionName = strtolower($identifier->getName());
			if (array_key_exists($functionName, $this->functionNodes)) {
				return $this->nodeToReflection($reflector, $this->functionNodes[$functionName]);
			}

			$files = $this->findFilesByFunction($functionName);
			foreach ($files as $file) {
				$fetchedNodesResult = $this->fileNodesFetcher->fetchNodes($file);
				$locatedSource = $fetchedNodesResult->getLocatedSource();
				$this->locatedSourcesByFile[$file] = $locatedSource;
				foreach ($fetchedNodesResult->getFunctionNodes() as $identifierName => $fetchedFunctionNode) {
					$this->functionNodes[$identifierName] = $fetchedFunctionNode;
				}
			}

			if (!array_key_exists($functionName, $this->functionNodes)) {
				return null;
			}

			return $this->nodeToReflection($reflector, $this->functionNodes[$functionName]);
		}

		return null;
	}

	/**
	 * @param Reflector $reflector
	 * @param FetchedNode<\PhpParser\Node\Stmt\ClassLike>|FetchedNode<\PhpParser\Node\Stmt\Function_> $fetchedNode
	 * @return Reflection
	 */
	private function nodeToReflection(Reflector $reflector, FetchedNode $fetchedNode): Reflection
	{
		$nodeToReflection = new NodeToReflection();
		$reflection = $nodeToReflection->__invoke(
			$reflector,
			$fetchedNode->getNode(),
			$this->locatedSourcesByFile[$fetchedNode->getFileName()],
			$fetchedNode->getNamespace()
		);

		if ($reflection === null) {
			throw new \PHPStan\ShouldNotHappenException();
		}

		return $reflection;
	}

	private function findFileByClass(string $className): ?string
	{
		if ($this->classToFile === null) {
			$this->init();
			if ($this->classToFile === null) {
				throw new \PHPStan\ShouldNotHappenException();
			}
		}

		if (!array_key_exists($className, $this->classToFile)) {
			return null;
		}

		return $this->classToFile[$className];
	}

	/**
	 * @param string $functionName
	 * @return string[]
	 */
	private function findFilesByFunction(string $functionName): array
	{
		if ($this->functionToFiles === null) {
			$this->init();
			if ($this->functionToFiles === null) {
				throw new \PHPStan\ShouldNotHappenException();
			}
		}

		if (!array_key_exists($functionName, $this->functionToFiles)) {
			return [];
		}

		return $this->functionToFiles[$functionName];
	}

	private function init(): void
	{
		$classToFile = [];
		$functionToFiles = [];
		foreach ($this->files as $file) {
			$symbols = $this->findSymbols($file);
			$classesInFile = $symbols['classes'];
			$functionsInFile = $symbols['functions'];
			foreach ($classesInFile as $classInFile) {
				$classToFile[$classInFile] = $file;
			}
			foreach ($functionsInFile as $functionInFile) {
				if (!array_key_exists($functionInFile, $functionToFiles)) {
					$functionToFiles[$functionInFile] = [];
				}
				$functionToFiles[$functionInFile][] = $file;
			}
		}

		$this->classToFile = $classToFile;
		$this->functionToFiles = $functionToFiles;
	}

	/**
	 * Inspired by Composer\Autoload\ClassMapGenerator::findClasses()
	 * @link https://github.com/composer/composer/blob/45d3e133a4691eccb12e9cd6f9dfd76eddc1906d/src/Composer/Autoload/ClassMapGenerator.php#L216
	 *
	 * @param string $file
	 * @return array{classes: string[], functions: string[]}
	 */
	private function findSymbols(string $file): array
	{
		$contents = @php_strip_whitespace($file);
		if ($contents === '') {
			return ['classes' => [], 'functions' => []];
		}

		if (!preg_match('{\b(?:class|interface|trait|function)\s}i', $contents)) {
			return ['classes' => [], 'functions' => []];
		}

		// strip heredocs/nowdocs
		$heredocRegex = '{
            # opening heredoc/nowdoc delimiter (word-chars)
            <<<[ \t]*+([\'"]?)(\w++)\\1
            # needs to be followed by a newline
            (?:\r\n|\n|\r)
            # the meat of it, matching line by line until end delimiter
            (?:
                # a valid line is optional white-space (possessive match) not followed by the end delimiter, then anything goes for the rest of the line
                [\t ]*+(?!\\2 \b)[^\r\n]*+
                # end of line(s)
                [\r\n]++
            )*
            # end delimiter
            [\t ]*+ \\2 (?=\b)
        }x';

		// run first assuming the file is valid unicode
		$contentWithoutHeredoc = preg_replace($heredocRegex . 'u', 'null', $contents);
		if ($contentWithoutHeredoc === null) {
			// run again without unicode support if the file failed to be parsed
			$contents = preg_replace($heredocRegex, 'null', $contents);
		} else {
			$contents = $contentWithoutHeredoc;
		}
		unset($contentWithoutHeredoc);

		if ($contents === null) {
			return ['classes' => [], 'functions' => []];
		}
		// strip strings
		$contents = preg_replace('{"[^"\\\\]*+(\\\\.[^"\\\\]*+)*+"|\'[^\'\\\\]*+(\\\\.[^\'\\\\]*+)*+\'}s', 'null', $contents);
		if ($contents === null) {
			return ['classes' => [], 'functions' => []];
		}
		// strip leading non-php code if needed
		if (strpos($contents, '<?') !== 0) {
			$contents = preg_replace('{^.+?<\?}s', '<?', $contents, 1, $replacements);
			if ($contents === null) {
				return ['classes' => [], 'functions' => []];
			}
			if ($replacements === 0) {
				return ['classes' => [], 'functions' => []];
			}
		}
		// strip non-php blocks in the file
		$contents = preg_replace('{\?>(?:[^<]++|<(?!\?))*+<\?}s', '?><?', $contents);
		if ($contents === null) {
			return ['classes' => [], 'functions' => []];
		}
		// strip trailing non-php code if needed
		$pos = strrpos($contents, '?>');
		if ($pos !== false && strpos(substr($contents, $pos), '<?') === false) {
			$contents = substr($contents, 0, $pos);
		}
		// strip comments if short open tags are in the file
		if (preg_match('{(<\?)(?!(php|hh))}i', $contents)) {
			$contents = preg_replace('{//.* | /\*(?:[^*]++|\*(?!/))*\*/}x', '', $contents);
			if ($contents === null) {
				return ['classes' => [], 'functions' => []];
			}
		}

		preg_match_all('{
            (?:
                 \b(?<![\$:>])(?P<type>class|interface|trait|function) \s++ (?P<byref>&\s*)? (?P<name>[a-zA-Z_\x7f-\xff:][a-zA-Z0-9_\x7f-\xff:\-]*+)
               | \b(?<![\$:>])(?P<ns>namespace) (?P<nsname>\s++[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\s*+\\\\\s*+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+)? \s*+ [\{;]
            )
        }ix', $contents, $matches);

		$classes = [];
		$functions = [];
		$namespace = '';

		for ($i = 0, $len = count($matches['type']); $i < $len; $i++) {
			if (!empty($matches['ns'][$i])) { // phpcs:disable
				$namespace = str_replace([' ', "\t", "\r", "\n"], '', $matches['nsname'][$i]) . '\\';
			} else {
				$name = $matches['name'][$i];
				// skip anon classes extending/implementing
				if ($name === 'extends' || $name === 'implements') {
					continue;
				}
				$namespacedName = strtolower(ltrim($namespace . $name, '\\'));

				if ($matches['type'][$i] === 'function') {
					$functions[] = $namespacedName;
				} else {
					$classes[] = $namespacedName;
				}
			}
		}

		return [
			'classes' => $classes,
			'functions' => $functions,
		];
	}

	public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType): array
	{
		return [];
	}

}
