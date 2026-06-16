<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminPermissionState;
use App\Entity\SchedulerTask;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class AdminSchedulerControllerTest extends WebTestCase
{
    use BackendAuthenticatedClientTrait;

    public function testAdminSchedulerListsEditsAndRunsRegisteredJobs(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);
        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $crawler = $client->request('GET', '/admin/scheduler');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Scheduler');
            self::assertStringContainsString('Live operation cleanup', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Cron syntax', (string) $client->getResponse()->getContent());

            $crawler = $client->request('GET', '/admin/scheduler/system.live_operation_cleanup');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Live operation cleanup');
            self::assertStringContainsString('Cron syntax', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Activate this job before running it manually.', (string) $client->getResponse()->getContent());

            $form = $crawler->filter('form.system-form')->form([
                'cron_expression' => '*/10 * * * *',
            ]);
            $form['enabled']->tick();
            $client->submit($form);
            self::assertResponseRedirects('/admin/scheduler/system.live_operation_cleanup');

            $this->assertSchedulerTaskStatus('system.live_operation_cleanup', SchedulerTaskStatus::Active, '*/10 * * * *');

            $crawler = $this->followAdminRedirect($client);
            $client->submit($crawler->selectButton('Run now')->form());
            self::assertResponseRedirects('/admin/scheduler/system.live_operation_cleanup');

            $this->followAdminRedirect($client);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Scheduler run completed with status completed.', (string) $client->getResponse()->getContent());
        } finally {
            $store->save($store->defaultOverrides(), 'test');
            $this->removeSchedulerTasks();
        }
    }

    public function testAdminSchedulerReadOnlyDisablesControlsAndRejectsMutations(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'operations:cleanup',
            '*/15 * * * *',
        );
        $task = $entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $task->activate('*/15 * * * *');
        $entityManager->persist($task);
        $entityManager->flush();
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);
        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        try {
            $crawler = $client->request('GET', '/admin/scheduler/system.live_operation_cleanup');

            self::assertResponseIsSuccessful();
            self::assertNotNull($crawler->filter('input[name="enabled"]')->attr('disabled'));
            self::assertNotNull($crawler->filter('input[name="cron_expression"]')->attr('disabled'));
            self::assertNotNull($crawler->selectButton('Save')->attr('disabled'));
            self::assertNotNull($crawler->selectButton('Run now')->attr('disabled'));

            $client->request('POST', '/admin/scheduler/system.live_operation_cleanup', [
                '_csrf_token' => (string) $crawler->filter('form.system-form input[name="_csrf_token"]')->attr('value'),
                'enabled' => '1',
                'cron_expression' => '*/5 * * * *',
            ]);

            self::assertResponseStatusCodeSame(401);

            $client->request('POST', '/admin/scheduler/system.live_operation_cleanup/run', [
                '_csrf_token' => (string) $crawler->filter('form[action="/admin/scheduler/system.live_operation_cleanup/run"] input[name="_csrf_token"]')->attr('value'),
            ]);

            self::assertResponseStatusCodeSame(401);
            $this->assertSchedulerTaskStatus('system.live_operation_cleanup', SchedulerTaskStatus::Active, '*/15 * * * *');
        } finally {
            $store->save($store->defaultOverrides(), 'test');
            $this->removeSchedulerTasks();
        }
    }

    public function testAdminSchedulerRunNowSurfacesFailedTaskResults(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $store = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $store);
        $store->save([
            'admin.scheduler' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'operations:cleanup',
            '*/15 * * * *',
        );
        $task = $entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $task->activate('not a cron');
        $entityManager->persist($task);
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/admin/scheduler/system.live_operation_cleanup');
            self::assertResponseIsSuccessful();

            $client->submit($crawler->selectButton('Run now')->form());
            self::assertResponseRedirects('/admin/scheduler/system.live_operation_cleanup');

            $this->followAdminRedirect($client);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'The scheduler run completed, but the selected job failed.',
                (string) $client->getResponse()->getContent(),
            );
        } finally {
            $store->save($store->defaultOverrides(), 'test');
            $this->removeSchedulerTasks();
        }
    }

    public function testSchedulerCronRouteGenerationIncludesBasePath(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);
        $context = $router->getContext();
        $context->setScheme('https');
        $context->setHost('example.test');
        $context->setBaseUrl('/studio');

        self::assertSame(
            'https://example.test/studio/cron/run',
            $router->generate('scheduler_cron_run', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    private function assertSchedulerTaskStatus(string $identifier, SchedulerTaskStatus $status, string $cronExpression): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $task = $entityManager->find(SchedulerTask::class, $identifier);

        self::assertInstanceOf(SchedulerTask::class, $task);
        self::assertSame($status, $task->status());
        self::assertSame($cronExpression, $task->cronExpression());
    }

    private function removeSchedulerTasks(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM scheduler_task_run WHERE task_identifier LIKE 'system.%'");
        $connection->executeStatement("DELETE FROM scheduler_task WHERE source = 'system'");
    }
}
