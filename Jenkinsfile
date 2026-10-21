@Library(['dockerHelpers', 'testing']) _
pipeline {
	agent any

	environment {
		// Docker repositories
		TEST_REGISTRY = 'rg.fr-par.scw.cloud/testing-images'

		// Images
		PHP_TEST_IMAGE = 'php:8.5'

		// Workspace directory inside container
		CONTAINER_WORKSPACE = '/workspace'
	}

	stages {
		stage('Docker Login') {
			steps {
				script {
					withCredentials([string(credentialsId: 'scaleway_secret_key', variable: 'SECRET')]) {
						dockerRegistryLogin(
							registryUrl: TEST_REGISTRY,
							password: env.SECRET
						)
					}
				}
			}
		}

		stage('Pull Test Image') {
			steps {
				sh "docker pull ${TEST_REGISTRY}/${PHP_TEST_IMAGE}"
			}
		}

		stage('Prepare Dependencies') {
			steps {
				script {
					// Need to update to refresh the lock file as it's not committed to the repository
					sh """
						docker run --rm \
							-v \$(pwd):${CONTAINER_WORKSPACE} \
							-w ${CONTAINER_WORKSPACE} \
							${TEST_REGISTRY}/${PHP_TEST_IMAGE} \
							sh -c 'composer update && composer install --prefer-dist --no-progress --no-interaction --optimize-autoloader'
					"""
				}
			}
		}

		stage('Testing') {
			steps {
				script {
					fullPhpTest(
						image: "${TEST_REGISTRY}/${PHP_TEST_IMAGE}",
						runInstall: true,
						phpstanCommand: './vendor/bin/phpstan analyse --memory-limit=1G',
						csCommand: './vendor/bin/pint --test',
						testCommand: './vendor/bin/phpunit'
					)
				}
			}
		}

		stage('Testing with PHPUnit 12') {
			steps {
				// The package supports PHPUnit ^12 || ^13; the stage above runs the newest.
				sh """
					docker run --rm \
						-v \$(pwd):${CONTAINER_WORKSPACE} \
						-w ${CONTAINER_WORKSPACE} \
						${TEST_REGISTRY}/${PHP_TEST_IMAGE} \
						sh -c 'git config --global --add safe.directory ${CONTAINER_WORKSPACE} && composer update --no-interaction --no-progress -W --with "phpunit/phpunit:^12.0" && ./vendor/bin/phpunit'
				"""
			}
		}

		stage('Trigger Satis rebuild') {
			steps {
				build job: 'internal/eSoul Internal/packages-repository/master', wait: false
			}
		}
	}
}
