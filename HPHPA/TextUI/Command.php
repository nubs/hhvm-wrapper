<?php
/**
 * hphpa
 *
 * Copyright (c) 2012, Sebastian Bergmann <sb@sebastian-bergmann.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package   hphpa
 * @author    Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright 2012 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @since     File available since Release 1.0.0
 */

/**
 * TextUI frontend.
 *
 * @author    Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright 2012 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   Release: @package_version@
 * @link      http://github.com/sebastianbergmann/hphpa/tree
 * @since     Class available since Release 1.0.0
 */
class HPHPA_TextUI_Command
{
    /**
     * Main method.
     */
    public function main()
    {
        $input = new ezcConsoleInput;

        $input->registerOption(
          new ezcConsoleOption(
            '',
            'checkstyle',
            ezcConsoleInput::TYPE_STRING
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            '',
            'ruleset',
            ezcConsoleInput::TYPE_STRING
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            '',
            'exclude',
            ezcConsoleInput::TYPE_STRING,
            array(),
            TRUE
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            'h',
            'help',
            ezcConsoleInput::TYPE_NONE,
            NULL,
            FALSE,
            '',
            '',
            array(),
            array(),
            FALSE,
            FALSE,
            TRUE
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            '',
            'suffixes',
            ezcConsoleInput::TYPE_STRING,
            'php',
            FALSE
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            '',
            'quiet',
            ezcConsoleInput::TYPE_NONE,
            NULL,
            FALSE
           )
        );

        $input->registerOption(
          new ezcConsoleOption(
            'v',
            'version',
            ezcConsoleInput::TYPE_NONE,
            NULL,
            FALSE,
            '',
            '',
            array(),
            array(),
            FALSE,
            FALSE,
            TRUE
           )
        );

        try {
            $input->process();
        }

        catch (ezcConsoleOptionException $e) {
            print $e->getMessage() . "\n";
            exit(1);
        }

        if ($input->getOption('help')->value) {
            $this->showHelp();
            exit(0);
        }

        else if ($input->getOption('version')->value) {
            $this->printVersionString();
            exit(0);
        }

        $arguments = $input->getArguments();

        if (empty($arguments)) {
            $this->showHelp();
            exit(1);
        }

        $checkstyle  = $input->getOption('checkstyle')->value;
        $excludes    = $input->getOption('exclude')->value;
        $rulesetFile = $input->getOption('ruleset')->value;
        $suffixes    = explode(',', $input->getOption('suffixes')->value);
        $quiet       = $input->getOption('quiet')->value;

        array_map('trim', $suffixes);

        $this->printVersionString();

        $finder = new Symfony\Component\Finder\Finder;

        try {
            foreach ($arguments as $argument) {
                $finder->in($argument);
            }

            foreach ($excludes as $exclude) {
                $finder->exclude($exclude);
            }

            foreach ($suffixes as $suffix) {
                $finder->name('*' . $suffix);
            }
        }

        catch (Exception $e) {
            $this->showError($e->getMessage());
        }

        $files = array();

        foreach ($finder as $file) {
            $files[] = $file->getRealpath();
        }

        if (!$rulesetFile) {
            $rulesetFile = $this->getDefaultRulesetFile();
        }

        try {
            $ruleset = new HPHPA_Ruleset($rulesetFile);
            $rules   = $ruleset->getRules();
        }

        catch (Exception $e) {
            $this->showError('Could not read ruleset.');
        }

        printf("Using ruleset %s\n\n", $rulesetFile);

        $analyzer = new HPHPA_Analyzer;
        $result   = new HPHPA_Result;
        $result->setRules($rules);

        try {
            $analyzer->run($files, $result);
        }

        catch (RuntimeException $e) {
            $this->showError($e->getMessage());
        }

        if ($checkstyle) {
            $report = new HPHPA_Report_Checkstyle;
            $report->generate($result, $checkstyle);
        }

        if (!$quiet) {
            $report = new HPHPA_Report_Text;
            $report->generate($result, 'php://stdout');
        }

        $numFilesWithViolations = 0;
        $numViolations          = 0;

        foreach ($result->getViolations() as $lines) {
            $numFilesWithViolations++;

            foreach ($lines as $violations) {
                $numViolations += count($violations);
            }
        }

        printf(
          "%sFound %d violation%s in %d file%s (out of %d total file%s).\n",
          !$quiet && $numViolations > 0 ? "\n" : '',
          $numViolations,
          $numViolations != 1 ? 's' : '',
          $numFilesWithViolations,
          $numFilesWithViolations != 1 ? 's' : '',
          count($files),
          count($files) != 1 ? 's' : ''
        );

        if ($numViolations > 0) {
            exit(1);
        }
    }

    /**
     * Shows an error.
     *
     * @param string $message
     * @since Method available since Release 1.0.4
     */
    protected function showError($message)
    {
        print $message . "\n";
        exit(1);
    }

    /**
     * Shows the help.
     */
    protected function showHelp()
    {
        $this->printVersionString();

        print <<<EOT
Usage: hphpa [switches] <directory|file> ...

  --checkstyle <file>     Write report in Checkstyle XML format to file.
  --ruleset <file>        Read list of rules to apply from XML file.

  --exclude <dir>         Exclude <dir> from code analysis.
  --suffixes <suffix>     A comma-separated list of file suffixes to check.

  --help                  Prints this usage information.
  --version               Prints the version and exits.

  --quiet                 Do not print violations.

EOT;
    }

    /**
     * Prints the version string.
     */
    protected function printVersionString()
    {
        print "hphpa @package_version@ by Sebastian Bergmann.\n\n";
    }

    /**
     * @return string
     * @since  Method available since Release 1.1.0
     */
    protected function getDefaultRulesetFile()
    {
        if (strpos('@data_dir@', '@data_dir') === 0) {
            return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'ruleset.xml';
        }

        return'@data_dir@' . DIRECTORY_SEPARATOR . 'hphpa' . DIRECTORY_SEPARATOR . 'ruleset.xml';
    }
}
