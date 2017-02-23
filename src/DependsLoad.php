<?php
/**
 * This class provides a before suite hook that parses DependsLoad annotation
 * and adds the dependent tests to the suite for execution.
 */
namespace Codeception\Extension;

use Codeception\Events;
use Codeception\Event\TestEvent;
use Codeception\Exception\TestParseException;
use Codeception\Test\Gherkin;
use Codeception\Test\Loader\Gherkin as GherkinLoader;
use Codeception\Test\Descriptor;
use Symfony\Component\Finder\Finder;

class DependsLoad extends \Codeception\Platform\Extension
{
    // List of events to listen
    public static $events = [
        Events::SUITE_BEFORE => 'beforeSuite',
        Events::TEST_START => 'testStart',
        Events::TEST_SUCCESS => 'testSuccess'
    ];

    protected static $signatures = [];

    protected $settings;

    private $gherkinLoader;

    protected $successfulTests = [];

    protected $dependentTests = [];

    private $suiteTests = [];

    private $serviceDi;

    private $testCollection;

    private $testGroups;

    private $testFilter;

    private $excludeGroups;

    public function beforeSuite(\Codeception\Event\SuiteEvent $suiteEvent)
    {
        $this->testGroups = $this->options['groups'];
        $this->testFilter = $this->options['filter'];
        $this->excludeGroups = $this->options['excludeGroups'];
        $this->settings = $suiteEvent->getSettings();
        $suite = $suiteEvent->getSuite();
        if(!$this->testFilter || !$this->testGroups || !$this->excludeGroups){
            $tests = $suite;
        }else {
            $tests = $suite->tests();
        }
        foreach ($tests as $id => $test) {
            $annotations = $test->getMetadata()->getGroups();
            if (is_a($test, 'Codeception\Test\Gherkin') && (empty($this->testGroups) || count(array_intersect($this->testGroups, $annotations)) !== 0)) {

                $this->serviceDi = [
                    'di' => $test->getMetadata()->getService('di'),
                    'dispatcher' => $test->getMetadata()->getService('dispatcher'),
                    'modules' => $test->getMetadata()->getService('modules')
                ];

                $depends = $this->parseAnnotation($annotations);

                if (!empty($depends)) {
                    $this->loadDependentTests($test, $depends);
                } else {
                    if (!in_array(md5($test->getSignature()), self::$signatures)) {
                        if (!empty($this->testGroups)) {
                            $test->getMetadata()->setGroups($this->testGroups);
                        }
                        $this->suiteTests[] = $test;
                        self::$signatures[] = md5($test->getSignature());
                    }
                }
            }
        }
        $suite->setTests($this->suiteTests);
        if (!empty($this->testGroups) || !empty($this->testFilter) || !empty($this->excludeGroups)) {
            $filterFactory = new \PHPUnit_Runner_Filter_Factory();
            $suite->injectFilter($filterFactory);
        }
    }

    public function testStart(TestEvent $event)
    {
        $test = $event->getTest();
        if (!$test instanceof Gherkin) {
            return;
        }
        $testSignatures = $test->getMetadata()->getDependencies();
        foreach ($testSignatures as $signature) {
            if (!in_array($signature, $this->successfulTests)) {
                $test->getMetadata()->setSkip("This test depends on $signature to pass");
                return;
            }
        }
    }

    public function testSuccess(TestEvent $event)
    {
        $test = $event->getTest();
        if (!$test instanceof Gherkin) {
            return;
        }
        $this->successfulTests[] = Descriptor::getTestSignature($test);
    }


    private function getGherkinLoader()
    {
        $this->gherkinLoader = new GherkinLoader($this->settings);
        return $this->gherkinLoader;
    }

    private function parseAnnotation($array)
    {

        $array = array_unique($array);
        $result = [];
        $re1 = '^(?:DependsLoad)(?:\s)+([\w]*):([\w\s"]*)$';

        foreach ($array as $a) {
            if ($c = preg_match_all("/" . $re1 . "/is", $a, $matches)) {
                $r['dir'] = $matches[1][0];
                $r['scenario'] = $matches[2][0];
                $result[] = $r;
            }
        }
        return $result;
    }

    private function loadDependentTests($test, $depends)
    {
        $testFile = $test->getMetadata()->getFilename();
        $testActor = $test->getMetadata()->getCurrent('actor');

        $testFileInfo = $this->parseTestFileName($testFile);
        $dependencies = [];
        foreach ($depends as $depend) {
            if (strcasecmp($testFileInfo['directory'][0], $depend['dir']) == 0 && strcasecmp($this->testCollection['dir'], $depend['dir']) == 0) {
                $dependTests = $this->testCollection['tests'];
            } else {
                $gherkinLoader = $this->loadTests($depend['dir'], $testFile);
                $dependTests = $gherkinLoader->getTests();

                $this->testCollection['dir'] = $depend['dir'];
                $this->testCollection['tests'] = $dependTests;
            }
            $dependsExist = false;
            foreach ($dependTests as $t) {
                $t->preload();
                if ($this->compareScenarioTitle($t->getSignature(), $depend['scenario'])) {
                    $dependsExist = true;
                    if (!in_array($t->getSignature(), $this->dependentTests)) {
                        $dependencies[] = $t->getSignature();
                        $this->dependentTests += $dependencies;

                        $annotations = $t->getMetadata()->getGroups();
                        $depends = $this->parseAnnotation($annotations);

                        if (!empty($depends)) {
                            $this->loadDependentTests($t, $depends);
                        }
                        $t->getMetadata()->setServices($this->serviceDi);
                        $t->getMetadata()->setCurrent(['actor' => $testActor]);

                        if (!in_array(md5($t->getSignature()), self::$signatures)) {
                            if (!empty($this->testGroups)) {
                                $t->getMetadata()->setGroups($this->testGroups);
                            }
                            $this->suiteTests[] = $t;
                            self::$signatures[] = md5($t->getSignature());
                        }
                    }
                }
            }

            if ($dependsExist === false) {
                throw new TestParseException(
                    $testFile, "DependsLoad - \"{$depend['scenario']}\" is invalid or not available in specified \"{$depend['dir']}\" feature directory."
                    . PHP_EOL .
                    "Make sure the directory and feature exists."
                );
            }
        }
        $test->getMetaData()->setDependencies($dependencies);
        if (!in_array(md5($test->getSignature()), self::$signatures)) {
            if (!empty($this->testGroups)) {
                $test->getMetadata()->setGroups($this->testGroups);
            }
            $this->suiteTests[] = $test;
            self::$signatures[] = md5($test->getSignature());
        }


    }

    private function loadTests($dirName, $testFile)
    {
        $gherkinLoader = $this->getGherkinLoader();

        $dirPath = $this->settings['path'] . $dirName;
        if ($this->isDirectory($dirPath)) {
            $files = $this->getAllFiles($dirPath);
            foreach ($files as $file) {
                if (strcasecmp($file->getExtension(), "feature") == 0) {
                    $pathName = str_replace(["//", "\\\\"], ["/", "\\"], $file->getPathname());
                    $gherkinLoader->loadTests($pathName);
                }
            }
            return $gherkinLoader;
        } else {
            throw new TestParseException(
                $testFile, "DependsLoad - \"{$dirName}\"  feature directory name is invalid or not available."
                . PHP_EOL .
                "Make sure the directory and scenario exists."
            );
        }
    }

    private function parseTestFileName($testFileName)
    {
        if (substr($testFileName, 0, strlen($this->settings["path"])) == $this->settings["path"]) {
            $testFileName = substr($testFileName, strlen($this->settings["path"]));
        }
        $fileInfo = pathinfo($testFileName);

        $result["filename"] = $fileInfo['filename'];
        $result["extension"] = $fileInfo['extension'];
        $result["directory"] = (!empty($fileInfo['dirname'])) ? explode('/', $fileInfo['dirname']) : [];

        return $result;
    }

    private function compareScenarioTitle($signature, $title)
    {
        $signature = explode("|", $signature, 2);
        $scenarioTitle = explode(":", $signature[0], 2);

        return (strcasecmp(trim($scenarioTitle[1]), $title) == 0) ? true : false;

    }

    private function getAllFiles($directory, $hidden = false)
    {
        return iterator_to_array(Finder::create()->files()->ignoreDotFiles(! $hidden)->in($directory), false);
    }

    private function isDirectory($directory)
    {
        return is_dir($directory);
    }
}