<?php

namespace ApplicationTest\SpaceApi;

// @todo do we need this line?
use ApplicationTest\Bootstrap;

use Application\SpaceApi\SpaceApiObject;

use ApplicationTest\PHPUnitUtil;
use PHPUnit_Framework_TestCase;
use SpaceApi\Validator\Validator;

class SpaceApiObjectTest extends \PHPUnit_Framework_TestCase
{
    // @depends and @dataProvider can't be combined, don't rely too much
    // on @dataProvider if you want to chain tests. If you use @depends
    // that function might need to be declared before the dependency
    // is actually used

    protected $validator = null;

    public function testSave() {

    }

    public function testUpdate() {
        $json = $this->providerJsonDataGood()[0][0];
        $spaceApiObject = SpaceApiObject::fromJson($json);

        $validator = $this->providerValidator()[0][0];
        $spaceApiObject->setValidator($validator);
        $this->assertEmpty($spaceApiObject->validation->getErrors());

        // remove the space field to force a validation error
        $object = json_decode($json);
        unset($object->space);
        $json = json_encode($object);

        $spaceApiObject = $spaceApiObject->update($json);
        $this->assertNotEmpty($spaceApiObject->validation->getErrors());
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testUpdateExpectedException() {
        $json = $this->providerJsonDataGood()[0][0];
        $spaceApiObject = SpaceApiObject::fromJson($json);
        $spaceApiObject->update('LOREM IPSUM');
    }

    public function testUpdateJsonChangedOnException() {
        $json = $this->providerJsonDataGood()[0][0];
        $spaceApiObject = SpaceApiObject::fromJson($json);

        $this->assertEquals($spaceApiObject->validJson, true);

        $test_string = 'LOREM IPSUM';

        try {
            $spaceApiObject->update($test_string);
        } catch (\Exception $e) {
            // we don't validate the exception type here,
            // testUpdateExpectedException() already does it
        }

        $this->assertEquals($spaceApiObject->json, $test_string);
        $this->assertEquals($spaceApiObject->validJson, false);
    }

    public function testFromFile() {
        // @todo implement
    }

    public function testFromJson() {

        $object_list = array();

        foreach ($this->providerJsonData() as $data) {
            $json = $data[0];
            $spaceApiObject = SpaceApiObject::fromJson($json);
            $this->assertNotNull($spaceApiObject);

            if ($json === '') {
                $this->assertNull($spaceApiObject->object);
                $this->assertEmpty($spaceApiObject->json);
            } else {
                $this->assertNotNull($spaceApiObject->object);
                $this->assertNotEmpty($spaceApiObject->json);
            }

            $object_list[] = $spaceApiObject;
            unset($json);
        }

        return $object_list;
    }

    /**
     * @depends testFromJson
     */
    public function testSetValidator() {

        $spaceApiObjectList = func_get_args()[0];

        /** @var SpaceApiObject $spaceApiObject */
        foreach ($spaceApiObjectList as $spaceApiObject) {
            $this->assertNull($spaceApiObject->validator);
            $spaceApiObject->setValidator($this->validator);
            $this->assertNotNull($spaceApiObject->validator);
        }

        return $spaceApiObjectList;
    }

    /**
     * @depends testSetValidator
     */
    public function testGetValidation() {
        $spaceApiObjectList = func_get_args()[0];

        /** @var SpaceApiObject $spaceApiObject */
        foreach ($spaceApiObjectList as $spaceApiObject) {
            // The validation property can only be null if no validator is set.
            $this->assertNotNull($spaceApiObject->validation);
        }

        return $spaceApiObjectList;
    }

    public function testSetGist() {

        $json = $this->providerJsonDataGood()[0][0];
        $spaceApiObject = SpaceApiObject::fromJson($json);

        // 1. test bad gist IDs, this must happen before testing the good
        //    ones because we couldn't test against the value 0 otherwise
        //    as $spaceApiObject->gist would be the last good gist ID tested
        $testGistId = array(' ', 'gas,', '#/*_', new \stdClass(), 0, array());
        foreach ($testGistId as $id) {
            $spaceApiObject->object->ext_gist = $id;

            PHPUnitUtil::callMethod(
                $spaceApiObject,
                'setGist',
                array($spaceApiObject->object)
            );

            $this->assertEquals($spaceApiObject->gist, 0);
        }

        // 2. test good gist IDs, this must happen after testing the bad
        //    ones, see the comment above
        $testGistId = array('1234', 'gaf123fasd', '12jlh14', 'adsf');
        foreach ($testGistId as $id) {
            $spaceApiObject->object->ext_gist = $id;

            PHPUnitUtil::callMethod(
                $spaceApiObject,
                'setGist',
                array($spaceApiObject->object)
            );

            $this->assertEquals($spaceApiObject->gist, $id);
        }
    }

    public function providerValidator() {
        $validator = new Validator();

        return array(
            array($validator)
        );
    }

    public function providerJsonDataGood() {
        $goodJson = <<<JSON
{
    "api": "0.13",
    "space": "Slopspace",
    "logo": "http://your-space.org/img/logo.png",
    "url": "http://your-space.org",
    "location": {
        "address": "Ulmer Strasse 255, 70327 Stuttgart, Germany",
        "lon": 9.236,
        "lat": 48.777
    },
    "contact": {
        "twitter": "@spaceapi"
    },
    "issue_report_channels": [
        "twitter"
    ],
    "state": {
        "icon": {
            "open": "http://example.com/open.gif",
            "closed": "http://example.com/closed.gif"
        },
        "open": null
    }
}
JSON;
        return array(
            array($goodJson),
        );
    }

    public function providerJsonDataBad() {
        return array(
            array('{}'),
            array(''),
        );
    }

    public function providerJsonData() {
        return array_merge_recursive(
            static::providerJsonDataGood(),
            static::providerJsonDataBad()
        );
    }

    public function setUp() {
        $this->validator = new Validator();
    }
}
