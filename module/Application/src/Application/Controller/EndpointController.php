<?php

namespace Application\Controller;

use Application\Endpoint\ConfigFile;
use Application\Endpoint\Endpoint;
use Application\Endpoint\EndpointList;
use Application\Exception\EmptyGistIdException;
use Application\Exception\EndpointExistsException;
use Application\Gist\Result;
use Application\Mail\EndpointMailInterface;
use Application\SpaceApi\SpaceApiObject;
use Application\SpaceApi\SpaceApiObjectFactory;
use Application\Token\Token;
use Application\Token\TokenList;
use Application\Utils\Utils;
use Doctrine\Common\Collections\Criteria;
use Rhumsaa\Uuid\Exception\UnsatisfiedDependencyException;
use Rhumsaa\Uuid\Uuid;
use Slopjong\JOL;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use ZendService\ReCaptcha\ReCaptcha;

class EndpointController extends AbstractActionController
{
    const SPACENAME_INVALID_TYPE    = 'InvalidHackerspaceName';
    const SPACENAME_INVALID_MESSAGE = 'The hackerspace name you provided is invalid. It must contain one alpha-numeric character at least.';
    const ENDPOINT_EXISTS_TYPE      = 'EndpointExists';
    const ENDPOINT_EXISTS_MESSAGE   = 'The endpoint already exists.';
    const ENDPOINT_SCRIPTS_DIR      = 'vendor/spaceapi/endpoint-scripts';

    public function indexAction()
    {
        $json = file_get_contents(static::ENDPOINT_SCRIPTS_DIR . '/spaceapi.json');
        $jsoneditor_default_input = json_decode($json);

        $this->layout('layout/landing');
        return array(
            'jsoneditor_default_input' => $jsoneditor_default_input,
        );
    }

    /**
     * There are two cases how this action got triggered:
     *
     *  # the action didn't get a json from another page and the
     *    page got directly 'entered' without form submission of
     *    the other page
     *
     *  # a page sends this action a json from which the space name
     *    should be extracted to be rendered in the name field. The
     *    json itself is then used as a template
     *
     * If another page wants to submit a json the POST parameter must
     * be called 'json'.
     *
     * @return array|ViewModel
     */
    public function createAction()
    {
        $submit = $this->params()->fromPost('submit');

        // 1. case => page directly entered
        // this is the non-normalized hackerspace name, don't forget
        // to use the normalize filter in the template or whereever you
        // need the slug.
        // NEVER output the string directly, escape it in the templates!
        $space = $this->params()->fromPost('hackerspace');

        // 2. case => another page submitted form data, if an empty
        //            string or non-json got submitted we handle the
        //            page request as if it had been called directly
        $requested_endpoint_data = null;
        $json = $this->params()->fromPost('json');
        if (! is_null($json) && $json !== '' ) {
            try {
                $requested_endpoint_data = SpaceApiObjectFactory::create(
                    $json, SpaceApiObjectFactory::FROM_JSON);

                // this won't be executed if $json is invalid
                $space = $requested_endpoint_data->name;
            } catch (\Exception $e) {
                // user submitted non-jso
            }
        }

        $config = $this->getServiceLocator()->get('config');

        $recaptcha = new ReCaptcha(
            $config['recaptcha']['public'],
            $config['recaptcha']['private'],
            array(
                'ssl'   => true,
            ),
            array(
                'theme' => 'clean',
            )
        );

        // we render the template immediately on the first visit or
        // if the challenge/response field is missing
        if (is_null($submit) ||
            !isset($_POST['recaptcha_challenge_field']) ||
            !isset($_POST['recaptcha_response_field'])
        ) {
            return array(
                'space'     => $space,
                'requested_endpoint_data' => $requested_endpoint_data,
                'recaptcha' => $recaptcha,
            );
        }

        // collect all recaptcha errors
        $recaptcha_errors = array();
        $result = null;

        /** @var \ZendService\ReCaptcha\Response $result */
        try {
            $result = $recaptcha->verify(
                $_POST['recaptcha_challenge_field'],
                $_POST['recaptcha_response_field']
            );
        } catch (\ZendService\ReCaptcha\Exception $e) {

            // $result will be null and thus an error message will be
            // defined already
            //$recaptcha_errors[] = $e->getMessage();
        }

        if (is_null($result) || !$result->isValid()) {

            $recaptcha_errors[] = 'Wrong input. Please try again!';

            return array(
                'space' => $space,
                'requested_endpoint_data' => $requested_endpoint_data,
                'recaptcha'        => $recaptcha,
                'recaptcha_errors' => $recaptcha_errors,
            );
        }

        $slug = Utils::normalize($space);

        // exit if the normalized hackerspace name is empty
        if (empty($slug)) {
            return array(
                'recaptcha' => $recaptcha,
                'space' => $space,
                'requested_endpoint_data' => $requested_endpoint_data,
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
            $token = Token::create($slug, $config['tokendir'])->getToken();

            // this throws an EndpointExistsException if the endoint exists
            $this->createEndpoint($slug, $token);

            // create a new gist and save its ID in the spaceapi json
            /** @var Result $gist_result */
            $gist_result = $this->createGist($slug);
            if ($gist_result->status === 201) {
                $this->saveGistId($gist_result->id, $slug);
            }

            // now save the user's json after the endpoint json template
            // has been 'gisted'
            if (! is_null($requested_endpoint_data)) {

                // @framework zend2
                $this->forward()->dispatch(
                    'Application\Controller\Endpoint',
                    array(
                        'action' => 'edit', // controller action
                        'token'  => $token, // action parameter
                        'edit_action'  => 'Save', // action parameter
                        'json'   => $requested_endpoint_data->json, // action parameter
                    )
                );
            }

            $email->send(
                "New endpoint created for $space",
                'New space created.'
            );

            // @framework zend2
            // createOkAction() will return a ViewModel
            return $this->forward()->dispatch(
                'Application\Controller\Endpoint',
                array(
                    'action' => 'create-ok',    // controller action
                    'token' => $token,          // action param
                    'gist'  => $gist_result,    // action param
                    'space' => $space,          // action param
                )
            );

        } catch (EndpointExistsException $e) {

            $email->send(
                "Endpoint creation failed for $space",
                static::ENDPOINT_EXISTS_MESSAGE
            );

            return array(
                'recaptcha' => $recaptcha,
                'space' => $space,
                'requested_endpoint_data' => $requested_endpoint_data,
                'error' => array(
                    'type'    => static::ENDPOINT_EXISTS_TYPE,
                    "message" => static::ENDPOINT_EXISTS_MESSAGE,
                ),
            );
        }
    }

    /**
     * Renders the page right after a new endpoint was created. On this
     * page the user will see the auto-generated token, the gist link
     * and the 'add your endpoint' note.
     *
     * This action is not supposed to be invoked by the front-end, however,
     * for testing purposes it's the direct access is not denied.
     *
     * The EndpointController::createAction() will dispatch automatically.
     *
     * @uses Route parameter 'token'
     * @uses Route parameter 'gist'
     * @uses Route parameter 'space'
     *
     * @return ViewModel
     */
    public function createOkAction() {

        $tpl_vars = array();

        $route_params = array('token', 'gist', 'space');

        // fill the template variables array with non-empty values
        foreach ($route_params as $param) {

            $p = $this->params()->fromRoute($param);

            // we use a variable here because arbitrary expressions in
            // empty() got introduced in PHP 5.5 but we might use 5.4
            // for a while
            if (!empty($p)) {
                $tpl_vars[$param] = $this->params()->fromRoute($param);
            }
        }

        // if $tpl_vars is totally empty right here we assume that the
        // action got invoked via the front-end, we'll use some fake data
        if (empty($tpl_vars)) {
            $gist_url = 'https://gist.github.com/endpoint-hosting/e0e0e5bda14489f06422';
            $tpl_vars = array(
                'token' => 'ZQ07gBv0XUn.CgwtBEoVju8ANtLwvAUAOJWpVIwG2HJzOqtG0BH8G',
                // mockup Application\Gist\Result
                'gist'  => json_decode('{ "url": { "html": "'. $gist_url .'" } }'),
                'space' => 'Estação H4ck3r',
            );
        }

        $view = new ViewModel($tpl_vars);

        // @todo this is hard-coded, there must be a way to compose the template
        //       file somewhat dynamically
        $view->setTemplate('application/endpoint/create-ok.twig');

        return $view;
    }

    /**
     * Action basically zips the endpoint scripts including the data
     * and offers the user a download. Internally it copies the scripts
     * from the deployment location because the vendor version might be
     * newer and no longer match the directory structure. Thus changes
     * done in the configuration file and .htaccess file during the
     * deployment must be reverted.
     *
     * @platform linux a couple of things are easier in bash such as "rm -rf"
     */
    public function downloadScriptsAction() {
        $token = $this->params()->fromPost('token');

        // dev-note:2
        if (is_null($token)) {
            // the RouteMatch
            $token = $this->params('token');
        }

        // no download if no token is provided
        if (is_null($token)) {
            $response = new Response();
            $response->setStatusCode(403);
        }

        /** @var TokenList $token_list */
        $token_list = $this->getServiceLocator()->get('TokenList');
        $slug = $token_list->getSlugFromToken($token);

        exec("cp -r public/space/$slug data/tmp/endpoint-download");

        // undo the RewriteBase change
        $htaccess_file = "data/tmp/endpoint-download/$slug/.htaccess";
        $htaccess_content = file_get_contents($htaccess_file);
        $htaccess_content = preg_replace("/RewriteBase.*/", "RewriteBase /", $htaccess_content);
        file_put_contents($htaccess_file, $htaccess_content);

        $config = new ConfigFile("data/tmp/endpoint-download/$slug/config.json");
        $config->setToken('');
        $config->save();

        // change to the directory so that the filepath in the zip file
        // doesn't match the endpoint hosting tmp directory
        $old_cwd = getcwd();
        chdir('data/tmp/endpoint-download');
        exec("/usr/bin/zip -r $slug.zip $slug");
        chdir($old_cwd);

        $zip_file = "data/tmp/endpoint-download/$slug.zip";
        if(file_exists($zip_file))
        {
            header("Content-Disposition: attachment; filename=endpoint-scripts.zip");
            header('Content-Type: application/zip');
            header("Content-Length: " . filesize($zip_file));
            readfile($zip_file);
        }

        exec("rm -rf data/tmp/endpoint-download/$slug*");
        exit();
    }

    /**
     * This action saves/validates a submitted JSON. This action is also
     * used by Endpoint::createAction(). The field 'api' is always set
     * to 0.13 internally.
     *
     * @uses $_POST['token']
     * @uses $_POST['edit_action'] either the string 'Save' or 'Validate'
     * @uses $_POST['json']
     */
    public function editAction()
    {
        // the Post
        $token = $this->params()->fromPost('token');

        // dev-note:2
        if (is_null($token)) {
            // the RouteMatch
            $token = $this->params('token');
        }

        // no edit if no token is provided
        if (is_null($token)) {
            return array();
        }

        /** @var TokenList $token_list */
        $token_list = $this->getServiceLocator()->get('TokenList');
        $slug = $token_list->getSlugFromToken($token);

        if (is_null($slug)) {
            return array(
                'error' => 'Invalid token!'
            );
        }

        $spaceapi = SpaceApiObjectFactory::create($slug, SpaceApiObjectFactory::FROM_NAME);

        $action = $this->params()->fromPost('edit_action');

        // dev-note:2
        if (is_null($action)) {
            $action = $this->params('edit_action');
        }

        $json = $this->params()->fromPost('json');

        // dev-note:2
        if (is_null($json)) {
            $json = $this->params('json');
        }

        // $json unmodified if invalid JSON
        $json = Utils::setApiToLatest($json);

        try {
            switch ($action) {

                case 'Save':

                    // update() can throw exception, updates the json
                    // in any case but doesn't save the object on filesystem
                    $spaceapi
                        ->update($json)
                        ->save();
                    $this->updateGist($spaceapi);

                    break;

                case 'Validate':

                    // update() can throw exception, updates the json
                    // in any case but doesn't save the object on filesystem
                    $spaceapi
                        ->update($json)
                        ->save();

                    break;

                default:
                    // this case happens on the 'Enter your token page'
//                    trigger_error('Edit action requested!');
            }
        } catch (\Exception $e) {
            // Briefly, nothing special needs to be done here.
            // This happens when the JSON could not be decoded, however,
            // the the json property in the SpaceApiObject instance got
            // updated but nothing got written back to the JSOn file
        }

        return array(
            'token'    => $token,
            'spaceapi' => $spaceapi,
        );
    }

    public function validateAjaxAction() {
        // curl -v --data-urlencode json='{"space":"test", "url":""}'  http://localhost:8090/endpoint/validate-ajax
        header('Content-type: application/json');
        $json = $this->params()->fromPost('json');
        $spaceApiObject = SpaceApiObjectFactory::create($json, SpaceApiObjectFactory::FROM_JSON);

        return $this->getResponse()->setContent(
            $spaceApiObject->validation->searilize()
        );
    }

    /**
     *
     */
    public function resetTokenAction() {

        // initialize the state machine
        $step = 1;

        /** @var EndpointList $endpoints */
        $endpoints = $this->getServiceLocator()->get('EndpointList');

        // if the space parameter is set enter 2nd state
        $slug = $this->params()->fromPost('slug');
        $check_file = $this->params()->fromPost('check_file');

        if (!empty($slug)) {
            $step = 2;
        }

        if (!empty($slug) && !empty($check_file)) {
            $step = 3;
        }

        try {

            // to this file we'll write a unique ID in step 2 or read
            // that ID in step 2 and 3
            $token_reset_file = "data/tmp/reset-token/$slug";

            switch ($step) {
                case 1:

                    return array(
                        'step'      => 1,
                        'endpoints' => $endpoints->toArray(),
                    );

                case 2:

                    // check if the space name is valid, if the user
                    // manipulated the input an exception is thrown
                    // and in the front-end an error message shows up
                    $criteria = Criteria::create()->where(
                        Criteria::expr()->eq('slug', $slug)
                    );

                    $found = $endpoints->matching($criteria);

                    if($found->count() !== 1) {
                        throw new \Exception("You are evil. Yes you are. Don't manipulate the form data.");
                    }

                    // if the token reset file exists from a previous
                    // step and is not older than 10 min, we'll reuse
                    // the unique ID

                    $age = 0; // in s
                    $max_age = 600; // in s
                    if (file_exists($token_reset_file)) {
                        $age = time() - filectime($token_reset_file);
                        if ($age < $max_age) {
                            $uid = file_get_contents($token_reset_file);
                            goto skip_new_uuid;
                        }
                    }

                    try {
                        $uid = Uuid::uuid4();
                    } catch (UnsatisfiedDependencyException $e) {
                        $uid = uniqid();
                        error_log('Using uniqid() fallback');
                    }

                    file_put_contents($token_reset_file, $uid);
                    $age = time() - filectime($token_reset_file);

                    skip_new_uuid:

                    return array(
                        'step'     => 2,
                        'spaceapi' => $found->first()->getSpaceApiObject(),
                        'uid'      => $uid,
                        'time_to_live' => array(
                            'seconds' => $max_age - $age,
                            'minutes' => ($max_age - $age) / 60
                        )
                    );

                case 3:

                    // check if the space name is valid, if the user
                    // manipulated the input an exception is thrown
                    // and in the front-end an error message shows up
                    $criteria = Criteria::create()->where(
                        Criteria::expr()->eq('slug', $slug)
                    );

                    $found = $endpoints->matching($criteria);

                    if($found->count() !== 1) {
                        throw new \Exception("You are evil. Yes you are. Don't manipulate the form data.");
                    }

                    /** @var Endpoint $endpoint */
                    $endpoint = $found->first();
                    $spaceApiObject = $endpoint->getSpaceApiObject();

                    $uid = file_get_contents($token_reset_file);
                    $file = $spaceApiObject->url . "/$uid.txt";

                    $ch = curl_init();
                    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt ($ch, CURLOPT_URL, $file);
                    curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 20);
                    curl_setopt ($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);

                    // Only calling the head
                    curl_setopt($ch, CURLOPT_HEADER, true); // header will be at output
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD'); // HTTP request is 'HEAD'

                    $content = curl_exec ($ch);
                    $info = curl_getinfo($ch);
                    curl_close ($ch);

                    if (is_array($info) && array_key_exists('http_code', $info)) {
                        if ($info['http_code'] !== 200) {
                            throw new \Exception('Resource not found.');
                        }
                    }

                    /** @var TokenList $tokens */
                    $tokens = $this->getServiceLocator()->get('TokenList');
                    $token = $tokens->getTokenFromSlug($slug);

                    if (!is_null($token)) {

                        // change the token in the token directory
                        $token->reset();

                        // now change the deployed endpoint's token too
                        $config = $endpoint->getConfig();
                        $config->setToken($token->getToken());
                        $config->save();

                        unlink($token_reset_file);
                    }

                    return array(
                        'step'  => 3,
                        'token' => $token->getToken()
                    );
            }
        } catch (\Exception $e) {
            return array(
                'error' => $e->getMessage(),
            );
        }

        return array();
    }

    //****************************************************************
    // HELPERS

    /**
     * Creates a new endpoint and adds a new entry to the space map.
     *
     * @param string $space The normalized space name
     * @param string $token A secret key
     * @throws \Application\Exception\EndpointExistsException
     * @platform linux Bash is used for 'rm -rf'
     */
    protected function createEndpoint($space, $token)
    {
        // create the new endpoint
        $file_path = "public/space/$space";

        if(file_exists($file_path))
            throw new EndpointExistsException();

        Utils::rcopy(static::ENDPOINT_SCRIPTS_DIR, $file_path);
        exec("rm -rf $file_path/.git");

        $baseUrl = $this->getRequest()->getBaseUrl();

        // fix the base url for the new endpoint
        $htaccess_file = $file_path . '/.htaccess';
        $htaccess_file_content = file_get_contents($htaccess_file);
        $htaccess_file_content = str_replace(
            "RewriteBase /",
            "RewriteBase $baseUrl/space/$space",
            $htaccess_file_content
        );
        file_put_contents($htaccess_file, $htaccess_file_content);

        // fix the secret key
        // @todo use the ConfigFile class
        $config_file = "$file_path/config.json";
        $config_file_content = file_get_contents($config_file);
        $config = json_decode($config_file_content);
        $config->api_key = $token;
        $config_file_content = Utils::json_encode($config);
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
            SpaceApiObjectFactory::create($slug, SpaceApiObjectFactory::FROM_NAME)->json
        );
    }

    /**
     * Updates a gist.
     *
     * There seems to be some trouble with the <em>github connection</em>
     * sometimes and no gist ID is available, This method is some sort
     * of self-repairing, a new gist will be created before pushing the
     * new content. If this happens a notification will be sent to the
     * SpaceAPI developers.
     *
     * @param SpaceApiObject $spaceapi
     * @return Result Empty array if posting to github failed
     */
    protected function updateGist(SpaceApiObject $spaceapi)
    {
        $config = $this->getServiceLocator()->get('config');
        $gist_file = $spaceapi->slug . ".json";

        if (empty($spaceapi->gist)) {
            $gist_result = $this->createGist($spaceapi->slug);
            $this->saveGistId($gist_result->id, $spaceapi->slug);

            /** @var EndpointMailInterface $email */
            $email = $this->getServiceLocator()->get('EndpointMail');

            if (empty($gist_result->id)) {
                $email->send(
                    "Failed to post an endpoint JSON a second time",
                    "Please check the gist " . $spaceapi->slug . ".json!"
                );
            } else {
                $email->send(
                    "Endpoint JSON posted twice",
                    "Please check the gist " . $spaceapi->slug . ".json!"
                );
            }
        }

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
            return;
        }

        $spaceapi = SpaceApiObjectFactory::create($slug, SpaceApiObjectFactory::FROM_NAME);
        $object = $spaceapi->object;
        $object->ext_gist = $gist_id;

        // we can only update from a full spaceapi json, there's some
        // internal logic doing more than just setting a property
        $spaceapi->update(Utils::json_encode($object));
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
        $spaceapi = SpaceApiObjectFactory::create($slug, SpaceApiObjectFactory::FROM_NAME)->object;
        unset($spaceapi->ext_gist);

        return Utils::json_encode($spaceapi);
    }
}
