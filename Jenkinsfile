@Library(['dockerHelpers', 'testing']) _
pipeline {
	agent any
	options {
		timeout(time: 20, unit: 'MINUTES')
	}

	environment {
		// Docker repositories
		TEST_REGISTRY = 'rg.fr-par.scw.cloud/testing-images'

		// Images
		PHP_TEST_IMAGE = 'php:8.5'
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
				sh "docker pull ${TEST_REGISTRY}/${PHP_TEST_IMAGE} || true"
			}
		}

		stage('Testing') {
			steps {
				script {
					// The lock file is not committed, so the install resolves the newest supported versions (PHPUnit 13).
					fullPhpTest(
						image: "${TEST_REGISTRY}/${PHP_TEST_IMAGE}",
						phpstanCommand: './vendor/bin/phpstan analyse --memory-limit=1G',
						csCommand: './vendor/bin/pint --test',
						testCommand: './vendor/bin/phpunit'
					)
				}
			}
		}

		stage('Testing with PHPUnit 12') {
			steps {
				script {
					// The package supports PHPUnit ^12 || ^13. Runs after the main suite because it rewrites vendor/.
					runCommandInTest(
						image: "${TEST_REGISTRY}/${PHP_TEST_IMAGE}",
						command: 'composer update --no-interaction --no-progress --with-all-dependencies --with "phpunit/phpunit:^12.0" && ./vendor/bin/phpunit'
					)
				}
			}
		}

		stage('Trigger Satis rebuild') {
			steps {
				build job: 'internal/eSoul Internal/packages-repository/master', wait: false
			}
		}
	}

	post {
		always {
			sh 'sudo /usr/local/sbin/jenkins-clean-workspace-perms "$WORKSPACE" || true'
			cleanWs()
		}
	}
}
