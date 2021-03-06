<?php
declare(strict_types=1);

namespace GitStats;

use GitStats\Formatter\Formatter;
use GitStats\Helper\CommandRunner;
use GitStats\Helper\Git;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class TaskRunner
{
    /**
     * @var Git
     */
    private $git;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var CommandRunner
     */
    private $commandRunner;

    /**
     * @var Application
     */
    private $application;

    public function __construct(
        Git $git,
        Filesystem $filesystem,
        CommandRunner $commandRunner,
        Application $application
    ) {
        $this->git = $git;
        $this->filesystem = $filesystem;
        $this->commandRunner = $commandRunner;
        $this->application = $application;
    }

    public function run(
        string $url,
        array $tasks = null,
        string $format = 'csv',
        int $max = null,
        bool $progress,
        InputInterface $input,
        ConsoleOutputInterface $output
    ) {
        $directory = $this->createTemporaryDirectory();

        $this->printDebug("Cloning <info>$url</info> in $directory", $output);
        $this->git->clone($url, $directory);

        $configuration = $this->loadConfiguration($directory, $tasks);

        // Get the list of commits
        $commits = $this->git->getCommitList($directory);
        if (is_int($max)) {
            $commits = array_splice($commits, 0, $max);
        }
        $this->printDebug(sprintf('Iterating through <info>%d</info> commits', count($commits)), $output);

        $stdout = new SymfonyStyle($input, $output);
        $stderr = $stdout->getErrorStyle();
        if ($progress) {
            $stderr->progressStart(count($commits));
        }

        $data = $this->processCommits($commits, $directory, $configuration['tasks']);

        $this->formatAndOutput($format, $stdout, $stderr, $configuration, $data, $progress);

        $this->printDebug('Done', $output);

        /** @var QuestionHelper $helper */
        $helper = $this->application->getHelperSet()->get('question');
        $question = new ConfirmationQuestion("<comment>Delete directory $directory? <info>[Y/n]</info></comment>");
        if ($helper->ask($input, $output, $question)) {
            $this->printDebug("Deleting $directory", $output);
            $this->filesystem->remove($directory);
        } else {
            $this->printDebug("Not deleting $directory", $output);
        }
    }

    private function processCommits(array $commits, $directory, array $tasks) : \Generator
    {
        foreach ($commits as $commit) {
            $this->git->checkoutCommit($directory, $commit);

            $timestamp = $this->git->getCommitTimestamp($directory, $commit);
            $data = [
                'commit' => $commit,
                'date' => date('Y-m-d H:i:s', $timestamp),
            ];

            foreach ($tasks as $taskName => $taskCommand) {
                $taskResult = $this->commandRunner->runInDirectory($directory, $taskCommand);
                $data[$taskName] = $taskResult;
            }

            yield $data;
        }
    }

    private function formatAndOutput(
        string $format,
        SymfonyStyle $stdout,
        SymfonyStyle $stderr,
        array $configuration,
        $data,
        bool $progress
    ) {
        $format = $format ?: 'csv';
        $formatterClass = sprintf('GitStats\Formatter\%sFormatter', ucfirst($format));
        /** @var Formatter $formatter */
        $formatter = new $formatterClass;
        $data = $formatter->format($configuration, $data);
        foreach ($data as $line) {
            $stdout->writeln($line);
            if ($progress) {
                $stderr->progressAdvance();
            }
        }
    }

    /**
     * Load configuration from the ".gitstats.yml" file in the target directory.
     *
     * @param array|null $tasks Filter the tasks to run.
     * @return array Configuration.
     */
    private function loadConfiguration(string $directory, array $tasks = null) : array
    {
        $configurationFile = $directory . '/.gitstats.yml';

        if (! file_exists($configurationFile)) {
            throw new \Exception('Configuration file ".gitstats.yml" is missing in the repository');
        }
        $configuration = Yaml::parse(file_get_contents($configurationFile));

        // Filter the tasks to run
        if ($tasks && ! empty($configuration['tasks'])) {
            $configuration['tasks'] = array_intersect_key($configuration['tasks'], array_flip($tasks));
        }

        return $configuration;
    }

    /**
     * @return string Directory path.
     */
    private function createTemporaryDirectory() : string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'gitstats_');

        // Turn the temporary file into a temporary directory
        $this->filesystem->remove($temporaryFile);
        $this->filesystem->mkdir($temporaryFile);

        return $temporaryFile;
    }

    /**
     * Print a debug message on stderr.
     */
    private function printDebug($message, ConsoleOutputInterface $output)
    {
        $output->getErrorOutput()->writeln("<comment>$message</comment>");
    }
}
