@Library(['testing', 'dockerHelpers', 'pipelines']) _

phpPackagePipeline(
    dependencies: 'update',
    matrix: [
        [name: 'PHP 8.4', image: 'php:8.4', update: ''],
        [name: 'PHP 8.6', image: 'php:8.6', update: ''],
        [name: 'PHPUnit 12', update: '--with-all-dependencies --with phpunit/phpunit:^12.0'],
    ],
    satis: true,
)
