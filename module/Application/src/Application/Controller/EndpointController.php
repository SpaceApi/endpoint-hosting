<?php

namespace Application\Controller;

use Application\Exception\EmptyGistIdException;
use Application\Exception\EndpointExistsException;
use Application\Gist\Result;
use Application\Mail\EndpointMailInterface;
use Application\Map\SpaceMap;
use Application\Map\SpaceMapList;
use Application\SpaceApi\SpaceApiObject;
use Application\Utils\Utils;
use Doctrine\Common\Collections\Criteria;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class EndpointController extends AbstractActionController
{
    const SPACENAME_INVALID_TYPE    = 'InvalidHackerspaceName';
    const SPACENAME_INVALID_MESSAGE = 'The hackerspace name you provided is invalid. It must contain at one alpha-numeric character at least.';
    const ENDPOINT_EXISTS_TYPE      = 'EndpointExists';
    const ENDPOINT_EXISTS_MESSAGE   = 'The endpoint already exists.';

    public function createAction()
    {
        $submit = $this->params()->fromPost('submit');

        // we render the template immediately on the first visit
        if (is_null($submit)) {
            return array();
        }

        // TODO: validate the captcha here

        $space = $this->params()->fromPost('hackerspace');
        $slug = Utils::normalize($space);

        // exit if the normalized hackerspace name is empty
        if (empty($slug)) {
            return array(
                'error' => array(
                    'type'    => static::SPACENAME_INVALID_TYPE,
                    'message' => static::SPACENAME_INVALID_MESSAGE,
                ),
            );
        }

        /** @var EndpointMailInterface $email */
        $email = $this->getServiceLocator()->get('EndpointMail');

        try {
            // generate a new token
            $token = Utils::generateSecret();

            // this throws an EndpointExistsException if the endoint exists
            $this->createEndpoint($slug, $token);
            $this->addSpaceMap($slug, $token);

            // create a new gist and save its ID in the spaceapi json
            /** @var Result $gist_result */
            $gist_result = $this->createGist($slug);
            if ($gist_result->status === 201) {
                $this->saveGistId($gist_result->id, $slug);
            }

            $email->send(
                "New endpoint created for $space",
                'New space created.'
            );

            $view = new ViewModel(array(
                'token' => $token,
                'gist'  => $gist_result
            ));

            $view->setTemplate('application/endpoint/create-ok.twig');

            return $view;

        } catch (EndpointExistsException $e) {

            $email->send(
                "Endpoint creation failed for $space",
                static::ENDPOINT_EXISTS_MESSAGE
            );

            return array(
                'error' => array(
                    'type'    => static::ENDPOINT_EXISTS_TYPE,
                    "message" => static::ENDPOINT_EXISTS_MESSAGE,
                ),
            );
        }
    }

    public function editAction()
    {
        $token = $this->params()->fromPost('token');

        // no edit if no token is provided
        if (is_null($token)) {
            return array();
        }

        $slug = $this->getSlugFromToken($token);

        if (is_null($slug)) {
            return array(
                'error' => 'Invalid token!'
            );
        }

        /** @var SpaceApiObject $spaceapi */
        $spaceapi = SpaceApiObject::fromName($slug);

        /*
         * validate = write json to file
         * save = write json to file and update gist
         */
        switch ($this->params()->fromPost('edit_action')) {

            case 'Save':

                $spaceapi
                    ->update($this->params()->fromPost('json'))
                    ->save();
                $this->updateGist($spaceapi);

                break;

            case 'Validate':

                $spaceapi
                    ->update($this->params()->fromPost('json'))
                    ->save();

                break;

            default:

                // this should never happen
                // TODO: output a warning or send an email
        }

        return array(
            'token'    => $token,
            'spaceapi' => $spaceapi,
        );
    }

    //****************************************************************
    // HELPERS

    /**
     * Creates a new endpoint and adds a new entry to the space map.
     *
     * @param string $space The normalized space name
     * @param string $token A secret key
     * @throws \Application\Exception\EndpointExistsException
     */
    protected function createEndpoint($space, $token)
    {
        // create the new endpoint
        $file_path = "public/space/$space";

        if(file_exists($file_path))
            throw new EndpointExistsException();

        Utils::rcopy('data/endpoint-scripts', $file_path);

        // fix the base url for the new endpoint
        $htaccess_file = $file_path . '/.htaccess';
        $htaccess_file_content = file_get_contents($htaccess_file);
        $htaccess_file_content = str_replace(
            "RewriteBase /",
            "RewriteBase /space/$space",
            $htaccess_file_content
        );
        file_put_contents($htaccess_file, $htaccess_file_content);

        // fix the secret key
        $config_file = "$file_path/config.json";
        $config_file_content = file_get_contents($config_file);
        $config = json_decode($config_file_content);
        $config->api_key = $token;
        $config_file_content = json_encode($config, JSON_PRETTY_PRINT);
        file_put_contents($config_file, $config_file_content);
    }

    /**
     * Creates a new gist and saves the ID to the json.
     *
     * @param string $slug
     * @return Result Empty array if posting to github failed
     */
    protected function createGist($slug)
    {
        $config = $this->getServiceLocator()->get('config');
        $gist_file = "$slug.json";

        return Utils::postGist(
            $config['gist_token'],
            $gist_file,
            SpaceApiObject::fromName($slug)->json
        );
    }

    /**
     * Updates a gist.
     *
     * @param SpaceApiObject $spaceapi
     * @return Result Empty array if posting to github failed
     */
    protected function updateGist(SpaceApiObject $spaceapi)
    {
        $config = $this->getServiceLocator()->get('config');
        $gist_file = $spaceapi->slug . ".json";

        return Utils::postGist(
            $config['gist_token'],
            $gist_file,
            $spaceapi->json,
            $spaceapi->gist
        );
    }

    /**
     * Writes the gist ID to the spaceapi JSON.
     *
     * @param int|string $gist_id
     * @param string $slug
     * @throws EmptyGistIdException
     */
    protected function saveGistId($gist_id, $slug)
    {
        if (empty($gist_id)) {
            throw new EmptyGistIdException('Empty gist ID provided.');
        }

        /** @var SpaceApiObject $spaceapi */
        $spaceapi = SpaceApiObject::fromName($slug);
        $object = $spaceapi->object;
        $object->ext_gist = $gist_id;

        // we can only update from a full spaceapi json, there's some
        // internal logic doing more than just setting a property
        $spaceapi->update(json_encode($object));
        $spaceapi->save();
    }

    /**
     * Loads the json and removes the gist ID.
     *
     * @param string $slug
     * @return string
     */
    protected function getSpaceApiJsonWithoutGist($slug)
    {
        $spaceapi = SpaceApiObject::fromName($slug)->object;
        unset($spaceapi->ext_gist);

        return json_encode($spaceapi,
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    /**
     * Adds a new space/token pair to the space map. This map is used
     * to reduce the amount of access of every space's config file.
     *
     * @param string $space_name
     * @param string $token
     */
    protected function addSpaceMap($space_name, $token)
    {
        /** @var SpaceMapList $map */
        $map = $this->getServiceLocator()->get('SpaceMapList');
        $map->addMap($space_name, $token);
        $this->saveSpaceMap($map);
    }

    /**
     * Saves the space map. Actually this method method belongs to
     * the SpaceMapList class but for some reason we must do this outside
     * that class.
     *
     * @see Comment in Application\Map\SpaceMapList
     * @param SpaceMapList $map
     */
    protected function saveSpaceMap(SpaceMapList $map)
    {
        // serialize the map
        $serializer = $this->getServiceLocator()->get('Serializer');
        $map = $serializer->serialize($map, 'json');

        // pretty print
        $map = json_encode(json_decode($map),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

        // write back to the map file
        file_put_contents('data/map.json', $map);
    }

    /**
     * Returns the slug or null for a given token.
     *
     * @param string $token
     * @return string|null Normalized space name or null if there's no match
     */
    protected function getSlugFromToken($token)
    {
        /** @var SpaceMapList $map */
        $map = $this->serviceLocator->get('SpaceMapList');

        $criteria = Criteria::create()->where(
            Criteria::expr()->eq('token', $token)
        );

        $found = $map->matching($criteria);

        if($found->count() > 0) {
            return $found->first()->slug;
        }

        return null;
    }
}
