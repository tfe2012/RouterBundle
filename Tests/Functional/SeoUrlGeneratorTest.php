<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\RouterBundle\Tests\Functional;

use ONGR\ElasticsearchBundle\DSL\Filter\IdsFilter;
use ONGR\ElasticsearchBundle\Test\ElasticsearchTestCase;

class SeoUrlGeneratorTest extends ElasticsearchTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function getDataArray()
    {
        return [
            'default' => [
                'product' => [
                    [
                        '_id' => 'non_matching_id_1',
                        'id' => 'non_matching_id_1',
                        'url' => [
                            ['url' => 'Product/Foo/Bär2/', 'key' => 'foo_bar'],
                        ],
                        'expired_url' => [],
                    ],
                    [
                        '_id' => 'test_id',
                        'id' => 'test_id',
                        'url' => [
                            ['url' => 'Product/Foo/Bär/', 'key' => 'foo_bar'],
                            ['url' => 'Product/Foö/Büg/', 'key' => 'foo_bug'],
                            ['url' => 'Product/Baz/', 'key' => 'baz'],
                            ['url' => 'Product/Baz/baz/', 'key' => 'baz_baz'],
                            ['url' => 'Product/Büz/bäß/', 'key' => 'buz_bas'],
                        ],
                        'expired_url' => [],
                    ],
                    [
                        '_id' => 'non_matching_id_2',
                        'id' => 'non_matching_id_2',
                        'url' => [],
                        'expired_url' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * Data provider for testUrlGenerator.
     *
     * @return array
     */
    public function getTestUrlGeneratorCases()
    {
        $out = [];

        // Case #0: full case. Url must be generated by key.
        $out[] = [
            'test_id',
            [
                '_seo_key' => 'baz',
                'test' => 'test',
            ],
            '/Product/Baz/?test=test',
        ];

        // Case #1: document has no URLs. Generate route for access with document id.
        $out[] = [
            'non_matching_id_2',
            [
                'test' => 'test',
            ],
            '/test/non_matching_id_2/?test=test',
        ];

        return $out;
    }

    /**
     * Tests router generate method.
     *
     * @param string $documentId  Document id.
     * @param array  $parameters  URL parameters for route generator.
     * @param string $expectedUrl Expected URL to be generated.
     *
     * @dataProvider getTestUrlGeneratorCases
     */
    public function testUrlGenerator($documentId, $parameters, $expectedUrl)
    {
        $repository = $this
            ->getManager()
            ->getRepository('AcmeTestBundle:Product');

        $search = $repository
            ->createSearch()
            ->addFilter(new IdsFilter([$documentId]));

        $parameters['document'] = $repository->execute($search)->current();

        /** @var \ONGR\RouterBundle\Routing\Router $router */
        $router = $this->getContainer()->get('router');
        $url = $router->generate('ongr_test_document_page', $parameters);

        $this->assertSame($expectedUrl, $url);
    }

    /**
     * Data provider for testUrlMatch().
     *
     * @return array
     */
    public function getTestUrlMatchCases()
    {
        $out = [];

        // Case #0: no redirect, should load specified link.
        $out[] = [
            'requestUrl' => '/Product/Foo/Bär/',
            'expectedUrl' => null,
            'redirect' => false,
            'expectedResponse' => ['document_id' => 'test_id', 'seo_key' => 'foo_bar'],
        ];

        // Case #1: with redirect.
        $out[] = [
            'requestUrl' => 'http://localhost/Product/baz/',
            'expectedUrl' => 'http://localhost/Product/Baz/',
            'redirect' => true,
            'expectedResponse' => null,
        ];

        // Case #2: with no URL, only document's ID.
        $out[] = [
            'requestUrl' => 'http://localhost/test/non_matching_id_2/',
            'expectedUrl' => null,
            'redirect' => false,
            'expectedResponse' => ['document_id' => 'non_matching_id_2'],
        ];

        // Case #3: no redirect, should load specified link.
        $out[] = [
            'requestUrl' => '/Product/Foö/Büg/',
            'expectedUrl' => null,
            'redirect' => false,
            'expectedResponse' => ['document_id' => 'test_id', 'seo_key' => 'foo_bug'],
        ];

        // Case #4: should redirect to lowercased version.
        $out[] = [
            'requestUrl' => '/Product/BÜz/bÄß/',
            'expectedUrl' => 'http://localhost/Product/Büz/bäß/',
            'redirect' => true,
            'expectedResponse' => ['document_id' => 'test_id', 'seo_key' => 'baz_bas'],
        ];

        return $out;
    }

    /**
     * Document with matching url must be passed to an action.
     *
     * @param string $requestUrl       Launch request using this URL.
     * @param string $expectedUrl      If redirecting, test against this URL.
     * @param bool   $isRedirect       Test if response is redirect.
     * @param array  $expectedResponse Expected response from controller.
     *
     * @dataProvider getTestUrlMatchCases()
     */
    public function testUrlMatch($requestUrl, $expectedUrl, $isRedirect, $expectedResponse)
    {
        $client = self::createClient();
        $client->request('GET', $requestUrl);

        $response = $client->getResponse();

        if ($isRedirect) {
            $this->assertTrue($response->isRedirection(), 'Should be redirection');
            $this->assertSame($expectedUrl, $response->headers->get('Location'));
        } else {
            $this->assertTrue($response->isOk(), 'response should be OK');
            $this->assertFalse($response->isRedirection(), 'should not be a redirect');

            $content = $response->getContent();
            $this->assertJsonStringEqualsJsonString(json_encode($expectedResponse), $content);
        }
    }
}
