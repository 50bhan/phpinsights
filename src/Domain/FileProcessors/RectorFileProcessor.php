<?php

declare(strict_types=1);

namespace NunoMaduro\PhpInsights\Domain\FileProcessors;

use LogicException;
use NunoMaduro\PhpInsights\Domain\Contracts\FileProcessor;
use NunoMaduro\PhpInsights\Domain\Contracts\Insight as InsightContract;
use NunoMaduro\PhpInsights\Domain\Details;
use NunoMaduro\PhpInsights\Domain\Insights\Decorators\RectorDecorator;
use NunoMaduro\PhpInsights\Domain\RectorContainer;
use PhpCsFixer\Differ\DifferInterface;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Rector\Core\Contract\Rector\PhpRectorInterface;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * @internal
 */
final class RectorFileProcessor implements FileProcessor
{
    private DifferInterface $differ;

    private Standard $prettyPrinter;

    private Lexer $lexer;

    private Parser $parser;

    /**
     * @var array<RectorDecorator>
     */
    private array $rectors = [];

    public function __construct(Parser $parser, Lexer $lexer, Standard $prettyPrinter, DifferInterface $differ)
    {
        $this->parser = $parser;
        $this->lexer = $lexer;
        $this->prettyPrinter = $prettyPrinter;
        $this->differ = $differ;
    }

    public function support(InsightContract $insight): bool
    {
        return $insight instanceof RectorDecorator;
    }

    public function addChecker(InsightContract $insight): void
    {
        if (! $insight instanceof RectorDecorator) {
            throw new RuntimeException(sprintf(
                'Unable to add %s, not a Rector instance',
                get_class($insight)
            ));
        }

        $this->rectors[] = $insight;
    }

    public function processFile(SplFileInfo $splFileInfo): void
    {
        $filePath = $splFileInfo->getRealPath();
        if ($filePath === false) {
            throw new LogicException('Unable to found file ' . $splFileInfo->getFilename());
        }

        foreach ($this->rectors as $rector) {
            if ($rector->skipFilesFromExcludedFiles($splFileInfo)) {
                continue;
            }

            try {
                [$newStmts, $oldStmts, $oldTokens] = $this->parseAndTraverseFileToNodes($splFileInfo);

                $newStmts = $this->refactor($rector, $newStmts);

                $newContent = $this->printFileContentToString($newStmts, $oldStmts, $oldTokens);
            } catch (Throwable $e) {
                $rector->addDetails($this->prepareErrorDetails($filePath, $e->getMessage()));

                continue;
            }

            $diff = $this->differ->diff($splFileInfo->getContents(), $newContent);

            if ($diff !== '') {
                $rector->addDetails($this->prepareDetails($rector, $filePath, $diff));
            }
        }
    }

    /**
     * @return array<array<Node>|mixed>
     */
    private function parseAndTraverseFileToNodes(SplFileInfo $splFileInfo): array
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new CloningVisitor());

        $oldStmts = $this->parser->parse($splFileInfo->getContents());
        $oldTokens = $this->lexer->getTokens();
        $newStmts = $nodeTraverser->traverse($oldStmts);

        return [$newStmts, $oldStmts, $oldTokens];
    }

    /**
     * @param array<Node> $statements
     *
     * @return array<Node>
     */
    private function refactor(RectorDecorator $rector, array $statements): array
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($this->getOriginalRector($rector));

        return $nodeTraverser->traverse($statements);
    }

    private function getOriginalRector(RectorDecorator $rector): PhpRectorInterface
    {
        return RectorContainer::get()->get($rector->getInsightClass());
    }

    /**
     * @param array<Node> $newStmts
     * @param array<Node> $oldStmts
     * @param array<string> $oldTokens
     */
    private function printFileContentToString(array $newStmts, array $oldStmts, array $oldTokens): string
    {
        return $this->prettyPrinter->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }

    private function prepareDetails(RectorDecorator $rector, string $file, string $diff): Details
    {
        return Details::make()
            ->setFile($file)
            ->setDiff($diff)
            ->setMessage($rector->getDefinition()->getDescription() . "\n" . $diff);
    }

    private function prepareErrorDetails(string $file, string $message): Details
    {
        return Details::make()
            ->setFile($file)
            ->setMessage("[ERROR] Could not process this file, due to: ${message}.");
    }
}
