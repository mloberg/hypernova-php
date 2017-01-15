<?php

/**
 * Created by PhpStorm.
 * User: beroberts
 * Date: 1/14/17
 * Time: 8:48 AM
 */

namespace WF\Hypernova\Tests;

use WF\Hypernova\Job;
use WF\Hypernova\JobResult;
use WF\Hypernova\Renderer;

class RendererTest extends \PHPUnit\Framework\TestCase
{
    private static $raw_server_response = '{"success":true,"error":null,"results":{"myView":{"name":"my_component","html":"<div data-hypernova-key=\"my_component\" data-hypernova-id=\"54f9f349-c59b-46b1-9e4e-e3fa17cc5d63\"><div>My Component</div></div>\n<script type=\"application/json\" data-hypernova-key=\"my_component\" data-hypernova-id=\"54f9f349-c59b-46b1-9e4e-e3fa17cc5d63\"><!--{\"foo\":{\"bar\":[],\"baz\":[]}}--></script>","meta":{},"duration":2.501506,"statusCode":200,"success":true,"error":null},"myOtherView":{"name":"my_component","html":"<div data-hypernova-key=\"my_component\" data-hypernova-id=\"54f9f349-c59b-46b1-9e4e-e3fa17cc5d63\"><div>My Component</div></div>\n<script type=\"application/json\" data-hypernova-key=\"my_component\" data-hypernova-id=\"54f9f349-c59b-46b1-9e4e-e3fa17cc5d63\"><!--{\"foo\":{\"bar\":[],\"baz\":[]}}--></script>","meta":{},"duration":2.501506,"statusCode":200,"success":true,"error":null}}}';

    /**
     * @var \WF\Hypernova\Renderer
     */
    private $renderer;

    /**
     * @var \WF\Hypernova\Job
     */
    private $defaultJob;

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $this->renderer = new \WF\Hypernova\Renderer('http://localhost:8080/batch');
        $this->defaultJob = new Job('my_component', ['foo' => ['bar' => [], 'baz' => []]]);
    }

    public function testCreateJobs()
    {
        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $job = ['name' => 'foo', 'data' => ['someData' => []]];

        $plugin->expects($this->once())
            ->method('getViewData')
            ->with($this->equalTo($job['name']), $this->equalTo($job['data']))
            ->willReturn($job['data']);

        $this->renderer->addPlugin($plugin);
        $this->renderer->addJob('id1', $job);

        $this->assertArrayHasKey('id1', $this->renderer->createJobs());
    }

    public function testMultipleJobsGetCreated()
    {
        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        for ($i = 0; $i < 5; $i++) {
            $this->renderer->addJob('id' . $i, $this->defaultJob);
        }

        $plugin->expects($this->exactly(5))
            ->method('getViewData');

        $this->renderer->addPlugin($plugin);

        $this->renderer->createJobs();
    }

    public function testPrepareRequestCallsPlugin()
    {
        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->exactly(2))
            ->method('prepareRequest')
            ->with($this->equalTo($this->defaultJob))
            ->willReturn($this->defaultJob);

        $this->renderer->addPlugin($plugin);
        $this->renderer->addPlugin($plugin);

        $allJobs = [$this->defaultJob];

        $this->assertEquals($allJobs, $this->renderer->prepareRequest($allJobs)[1]);
    }

    public function testShouldSend()
    {
        $pluginDontSend = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);
        $pluginDoSend = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $pluginDontSend->expects($this->once())
            ->method('shouldSendRequest')
            ->willReturn(false);

        $pluginDoSend->expects($this->never())
            ->method('shouldSendRequest');

        $this->renderer->addPlugin($pluginDontSend);
        $this->renderer->addPlugin($pluginDoSend);

        $this->assertFalse($this->renderer->prepareRequest([$this->defaultJob])[0]);
    }

    public function testRenderShouldNotSend()
    {
        $renderer = $this->getMockedRenderer(false);

        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        foreach (['willSendRequest', 'onError', 'onSuccess', 'afterResponse'] as $methodThatShouldNotBeCalled) {
            $plugin->expects($this->never())
                ->method($methodThatShouldNotBeCalled);
        }

        $renderer->addPlugin($plugin);

        /**
         * @var \WF\Hypernova\Response $response
         */
        $response = $renderer->render();

        $this->assertInstanceOf(\WF\Hypernova\Response::class, $response);
        $this->assertNull($response->error);

        $this->assertStringStartsWith('<div data-hypernova-key="my_component"', $response->results['id1']->html);
    }

    public function testGetViewDataHandlesExceptions()
    {
        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->once())
            ->method('getViewData')
            ->willThrowException(new \Exception('something went wrong'));

        $plugin->expects($this->once())
            ->method('onError');

        $this->renderer->addJob('id1', $this->defaultJob);
        $this->renderer->addPlugin($plugin);

        $this->assertEquals(['id1' => $this->defaultJob], $this->renderer->createJobs());
    }


    /**
     * @dataProvider errorPluginProvider
     */
    public function testPrepareRequestErrorsCauseFallback($plugin)
    {
        $renderer = $this->getMockBuilder(Renderer::class)
            ->disableOriginalConstructor()
            ->setMethods(['createJobs'])
            ->getMock();

        $renderer->expects($this->once())
            ->method('createJobs')
            ->willReturn(['id1' => $this->defaultJob]);

        $renderer->addPlugin($plugin);

        /**
         * @var \WF\Hypernova\Response $response
         */
        $response = $renderer->render();

        $this->assertInstanceOf(\WF\Hypernova\Response::class, $response);
        $this->assertNotEmpty($response->error);

        $this->assertStringStartsWith('<div data-hypernova-key="my_component"', $response->results['id1']->html);
    }

    public function errorPluginProvider()
    {
        $pluginThatThrowsInPrepareRequest = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $pluginThatThrowsInPrepareRequest->expects($this->once())
            ->method('prepareRequest')
            ->willThrowException(new \Exception('Exception in prepare request'));

        $pluginThatThrowsInShouldSendRequest = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $pluginThatThrowsInShouldSendRequest->expects($this->once())
            ->method('shouldSendRequest')
            ->willThrowException(new \Exception('Exception in should send request'));

        foreach ([$pluginThatThrowsInPrepareRequest, $pluginThatThrowsInShouldSendRequest] as $plugin) {
            foreach (['willSendRequest', 'onError', 'onSuccess', 'afterResponse'] as $methodThatShouldNotBeCalled) {
                $plugin->expects($this->never())
                    ->method($methodThatShouldNotBeCalled);
            }
        }

        return [
            [$pluginThatThrowsInPrepareRequest],
            [$pluginThatThrowsInShouldSendRequest]
        ];
    }

    public function testWillSendRequest()
    {
        $renderer = $this->getMockedRenderer(true);

        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->once())
            ->method('willSendRequest')
            ->with($this->equalTo(['id1' => $this->defaultJob]));

        $renderer->addPlugin($plugin);

        $renderer->render();
    }

    public function testOnSuccess() {
        $renderer = $this->getMockedRenderer(true);

        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->exactly(2))
            ->method('onSuccess');

        $renderer->addPlugin($plugin);

        $renderer->render();
    }

    public function testAfterResponse() {
        $renderer = $this->getMockedRenderer(true);

        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->once())
            ->method('afterResponse');

        $renderer->addPlugin($plugin);

        $renderer->render();
    }

    public function testOnErrorInFinalize()
    {
        $renderer = $this->getMockBuilder(Renderer::class)
            ->disableOriginalConstructor()
            ->setMethods(['prepareRequest', 'getClient', 'doRequest'])
            ->getMock();

        $renderer->expects($this->once())
            ->method('prepareRequest')
            ->willReturn([true, [$this->defaultJob]]);

        $renderer->expects($this->once())
            ->method('doRequest')
            ->willReturn(['id1' => JobResult::fromServerResult(['success' => false, 'error' => 'an error!', 'html' => null], $this->defaultJob)]);

        $plugin = $this->createMock(\WF\Hypernova\Plugins\BasePlugin::class);

        $plugin->expects($this->once())
            ->method('onError')
            ->with($this->equalTo('an error!'), $this->anything());

        $renderer->addPlugin($plugin);

        $renderer->render();
    }


    /**
     * Helper fn to get a mocked renderer which will correctly send data through past the `prepareRequest` stage.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|\WF\Hypernova\Renderer
     */
    private function getMockedRenderer($shouldSendRequest, $clientResponseCode = 200)
    {
        $renderer = $this->getMockBuilder(Renderer::class)
            ->disableOriginalConstructor()
            ->setMethods(['prepareRequest', 'getClient'])
            ->getMock();

        $renderer->expects($this->once())
            ->method('prepareRequest')
            ->willReturn([$shouldSendRequest, ['id1' => $this->defaultJob]]);

        $renderer->addJob('myView', $this->defaultJob);
        $renderer->addJob('myOtherView', $this->defaultJob);

        $mockHandler = new \GuzzleHttp\Handler\MockHandler(
            [
                new \GuzzleHttp\Psr7\Response($clientResponseCode,
                    [],
                    $clientResponseCode == 200 ? self::$raw_server_response : null
                )
            ]
        );
        $handler = \GuzzleHttp\HandlerStack::create($mockHandler);

        $renderer->expects($this->any())
            ->method('getClient')
            ->willReturn(new \GuzzleHttp\Client(['handler' => $handler]));

        return $renderer;
    }
}