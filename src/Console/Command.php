<?php
/**
 * phpjunitmerge
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2015, Andreas Weber <code@andreas-weber.me>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE
 *
 * @package   phpjunitmerge
 * @author    Andreas Weber <code@andreas-weber.me>
 * @copyright 2015 Andreas Weber <code@andreas-weber.me>
 * @license   http://opensource.org/licenses/MIT The MIT License
 * @since     File available since Release 1.0.0
 */

namespace AndreasWeber\PHPJUNITMERGE\Console;

use Symfony\Component\Console\Command\Command as AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use TheSeer\fDOM\fDOMDocument;

/**
 * Base command class.
 *
 * @author    Andreas Weber <code@andreas-weber.me>
 * @copyright 2015 Andreas Weber <code@andreas-weber.me>
 * @license   http://opensource.org/licenses/MIT The MIT License
 * @link      https://github.com/andreas-weber/php-junit-merge
 * @since     Class available since Release 1.0.0
 */
class Command extends AbstractCommand
{
    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setName('phpjunitmerge')
            ->setDefinition(
                array(
                    new InputArgument(
                        'dir',
                        InputArgument::REQUIRED,
                        'Directory where all files ready to get merged are stored'
                    ),
                    new InputArgument(
                        'file',
                        InputArgument::REQUIRED,
                        'The target file in which the merged result should be written'
                    )
                )
            )
            ->addOption(
                'names',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of file names to check',
                '*.xml'
            )
            ->addOption(
                'ignore',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of file names to ignore',
                'result.xml'
            )
			->addOption(
				'no-suffix',
				null,
				InputOption::VALUE_NONE,
				'Do not add suffix for test suites with duplicate names',
				null
			);
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|integer null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getArgument('dir');
        $fileOut = $input->getArgument('file');

        $names = $input->getOption('names');
        if ($names) {
            $names = explode(',', $names);
        } else {
            $names = array();
        }

        $ignoreNames = $input->getOption('ignore');
        if ($ignoreNames) {
            $ignoreNames = explode(',', $ignoreNames);
        } else {
            $ignoreNames = array();
        }

		$noSuffix = $input->hasParameterOption('--no-suffix');
        // here is where the magic happens
        $files = $this->findFiles($directory, $names, $ignoreNames);
        $outXml = $this->mergeFiles(realpath($directory), $files, $noSuffix);
        $result = $this->writeFile($outXml, $fileOut);

        $output->writeln(
            '<info>Found and processed ' . count($files) . ' files. Wrote merged result in \'' . $fileOut . '\'.</info>'
        );

        return (true === $result) ? 0 : 1;
    }

    /**
     * Find all files to merge.
     *
     * @param string $directory
     * @param array  $names
     * @param array  $ignoreNames
     *
     * @return Finder
     */
    private function findFiles($directory, array $names, array $ignoreNames)
    {
        $finder = new Finder();
        $finder->files()->in($directory);

        foreach ($names as $name) {
            $finder->name($name);
        }

        foreach ($ignoreNames as $name) {
            $finder->notName($name);
        }

        $finder->sortByName();

        return $finder;
    }

    /**
     * Merge all files.
     *
     * @param string $directory
     * @param Finder $finder
	 * @param bool $noSuffix
     *
     * @return fDOMDocument
     */
    private function mergeFiles($directory, Finder $finder, $noSuffix)
    {
        $outXml = new fDOMDocument;
        $outXml->formatOutput = true;

        $outTestSuites = $outXml->createElement('testsuites');
        $outXml->appendChild($outTestSuites);

        $outTestSuite = $outXml->createElement('testsuite');
        $outTestSuites->appendChild($outTestSuite);

        $tests = 0;
        $assertions = 0;
        $failures = 0;
        $errors = 0;
        $time = 0;

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if ($this->isFileEmpty($file)) {
                continue;
            }

            $inXml = $this->loadFile($file->getRealpath());
            foreach ($inXml->query('//testsuites/testsuite') as $inElement) {

				if (!$noSuffix) {
					$inName = $inElement->getAttribute('name');
					$outName = $inName;
					$suffix = 2;
					while ($outTestSuite->query('//testsuite[@name="' . $outName . '"]')->length !== 0) {
						$outName = $inName . '_' . $suffix;
						$suffix++;
					}
				}

                $outElement = $outXml->importNode($inElement, true);
				if (!$noSuffix) {
					$outElement->setAttribute('name', $outName);
				}
                $outTestSuite->appendChild($outElement);

                $tests += $inElement->hasAttribute('tests') ? $inElement->getAttribute('tests') : 0;
                $assertions += $inElement->hasAttribute('assertions') ? $inElement->getAttribute('assertions') : 0;
                $failures += $inElement->hasAttribute('failures') ? $inElement->getAttribute('failures') : 0;
                $errors += $inElement->hasAttribute('errors') ? $inElement->getAttribute('errors') : 0;
                $time += $inElement->hasAttribute('time') ? $inElement->getAttribute('time') : 0;
            }
        }

        $outTestSuite->setAttribute('name', $directory);
        $outTestSuite->setAttribute('tests', $tests);
        $outTestSuite->setAttribute('assertions', $assertions);
        $outTestSuite->setAttribute('failures', $failures);
        $outTestSuite->setAttribute('errors', $errors);
        $outTestSuite->setAttribute('time', $time);

        return $outXml;
    }

    /**
     * Load an xml junit file.
     *
     * @param string $filename
     *
     * @return fDOMDocument
     */
    private function loadFile($filename)
    {
        $dom = new fDOMDocument();
        $dom->load($filename);

        return $dom;
    }

    /**
     * Checks if a file is empty.
     *
     * @param SplFileInfo $file
     *
     * @return bool
     */
    private function isFileEmpty(SplFileInfo $file)
    {
        return $file->getSize() > 0 ? false : true;
    }

    /**
     * Writes the merged result file.
     *
     * @param fDOMDocument $dom
     * @param string       $filename
     *
     * @return bool
     */
    private function writeFile(fDOMDocument $dom, $filename)
    {
        $dom->formatOutput = true;
        $result = $dom->save($filename, 0);

        return ($result !== false) ? true : false;
    }
}
