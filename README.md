# Codeception Gherkin DependsLoad

The [Codeception](http://codeception.com/) extension for supporting dynamically loading dependent features for Gherkin tests using `@dependsload` annotation.

## Minimum Requirements

- Codeception 2.2
- PHP 5.5

## Installation
The extension can be installed using [Composer](https://getcomposer.org)

```bash
$ composer require labbots/codeception-gherkin-dependsload
```

Be sure to enable the extension in `codeception.yml` as shown in
[configuration](#configuration) below.
## Configuration
Enabling **DependsLoad** annotation in your tests is done in `codeception.yml`.

```yaml
extensions:
    enabled:
        - Codeception\Extension\DependsLoad
```

## Usage
Once installed you will be able to use the `@dependsload` annotation in the feature files for defining the
scenarios that you would like to execute before the test. This annotation will make sure the dependent tests are executed before the actual test and you can also specify scenarios from other feature files. The extension automatically loads scenarios from specified file.

```
@dependsload <feature_dirname>:<scenario_title>
```

## Example

The directory structure for acceptance test is as follows
```
.
+-- acceptance
|   +-- prepareSystem
|        +-- prepareData.feature
|        +-- prepareSystem.feature
|   +-- loginTest
|        +-- loginTest.feature
```

**Note:** The sub directory structure is important for the annotation to work because the sub directory name is used to load all the features under that specified directory.

Feature File 1: This file is under prepareSystem sub directory

```gherkin
Feature: Greg blog login

  Scenario: Login to blog
    Given a global administrator named "Greg"
    And a blog named "Greg's anti-tax rants"
    And a customer named "Wilson"
    And a blog named "Expensive Therapy" owned by "Wilson"

```
Feature file 2: Thie file is under loginTest sub directory
```gherkin
Feature: Multiple site support

  Scenario: Wilson posts to his own blog
    Given I am logged in as Wilson
    When I try to post to "Expensive Therapy"
    Then I should see "Your article was published."
	
  @dependsload prepareSystem:Login to blog
  Scenario: Greg posts to a client's blog
    Given I am logged in as Greg
    When I try to post to "Expensive Therapy"
    Then I should see "Your article was published."
```