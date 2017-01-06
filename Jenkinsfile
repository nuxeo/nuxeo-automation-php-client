/*
 * (C) Copyright 2016 Nuxeo SA (http://nuxeo.com/) and contributors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Contributors:
 *     Pierre-Gildas MILLON <pgmillon@nuxeo.com>
 */

node('SLAVE') {
    try {
        wrap([$class: 'TimestamperBuildWrapper']) {
            stage 'checkout'
            checkout scm
            docker.image('quay.io/nuxeo/nuxeo-qaimage-php').pull()
            docker.build('nuxeo-qaimage-php-client', 'docker/qa').inside {
                stage 'build'
                sh 'rm -rf vendor && composer install'
                stage 'tests'
                sh 'php vendor/bin/phpunit'
                stage 'dependencies vulnerability check'
                sh 'php vendor/bin/security-checker security:check'
            }
        }
    } catch (e) {
        step([$class: 'ClaimPublisher'])
        throw e
    }
}
