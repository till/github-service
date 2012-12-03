<?php
namespace till;

class GithubService
{
    protected $id;

    protected $rateLimit;

    protected $secret;

    /**
     * @param string $id     The client id.
     * @param string $secret The client secret.
     *
     * @return $this
     */
    public function __construct($id, $secret)
    {
        $this->id     = $id;
        $this->secret = $secret;
    }

    /**
     * Wrap the request to Github!
     *
     * @param \HTTP_Request2 $request
     * @param mixed          $token
     *
     * @return \HTTP_Request2_Response
     */
    protected function doRequest(\HTTP_Request2 $request, $token = null)
    {
        try {
            if (null !== $token) {
                $request->setHeader('Authorization', sprintf('token %s', $token));
            }
            return $request->send();
        } catch (\HTTP_Request2_Exception $e) {
            throw new \RuntimeException(
                "Communication with Github failed miserabily.",
                null,
                $e
            );
        }
    }

    /**
     * @return \HTTP_Request2
     */
    protected function getClient()
    {
        static $client;
        if ($client === null) {
            $client = new \HTTP_Request2();
            $client->setAdapter(new \HTTP_Request2_Adapter_Curl());
            $client->setHeader('Accept', 'application/json');
        }
        return $client;
    }

    /**
     * A wrapper to return multiple pages from the API!
     *
     * @param \HTTP_Request2 $client
     * @param string         $token
     *
     * @return array
     */
    protected function getMultiple($client, $token)
    {
        $data = array();

        while (true) {

            $response = $this->doRequest($client, $token);
            $pageData = $this->parseResponse($response);
            $data     = array_merge($data, $pageData);

            if (null === ($links = $response->getHeader('link'))) {
                break;
            }

            $links = explode(', ', $links);
            if (0 === count($links)) {
                break;
            }

            foreach ($links as $link) {

                list($link_url, $rel) = explode('; ', $link);

                switch ($rel) {
                case 'rel="next"':
                case 'rel="last"':
                    $url = substr($link_url, 1, -1);
                    $client->setUrl($url);
                    break 2;
                case 'rel="first"':
                    break 3;
                }
            }
        }

        return $data;
    }

    /**
     * @return \stdClass
     * @throws \InvalidArgumentException When the response from Github is broken.
     * @throws \RuntimeException         When we receive an error from Github.
     * @throws \DomainException          When shit is disabled, gone.
     */
    protected function parseResponse(\HTTP_Request2_Response $response)
    {
        $data = @json_decode($response->getBody());
        if (!($data instanceof \stdClass) && !is_array($data)) {
            throw new \InvalidException("Github responded with broken json.");
        }

        $this->rateLimit        = new \stdClass;
        $this->rateLimit->quota = $response->getHeader('x-ratelimit-limit');
        $this->rateLimit->used  = $response->getHeader('x-ratelimit-remaining');

        if (200 !== $response->getStatus()) {
            if (isset($data->message)) {
                $msg = $data->message;
            } else {
                $msg = "unknown";
            }
            switch ($response->getStatus()) {
            case 410:
                throw new \DomainException($msg, $response->getStatus());
            default:
                throw new \RuntimeException("Error from Github: {$msg}", $response->getStatus());
            }
        }

        if (($data instanceof \stdClass) && isset($data->error)) {
            throw new \RuntimeException("Received error status code: {$data->error}", 400);
        }

        return $data;
    }

    /**
     * @param int       $issue      The number of the issue.
     * @param string    $login      The owner of the repository or organization.
     * @param string    $repository The name of the repository.
     * @param \stdClass $body       The request body.
     * @param string    $token      The access token.
     * @return \stdClass
     */
    protected function updateIssue($issue, $login, $repository, $body, $token)
    {
        $client = $this->getClient();

        $client->setUrl("https://api.github.com/repos/{$login}/{$repository}/issues/{$issue}")
            ->setHeader('Content-Type: application/json')
            ->setMethod("PATCH")
            ->setBody(json_encode($body));

        $response = $this->doRequest($client, $token);
        $issue    = $this->parseResponse($response);
        return $issue;
    }

    public function assignIssue($issue, $user, $login, $repository, $token)
    {
        $body           = new \StdClass;
        $body->assignee = $user;

        $issue = $this->updateIssue($issue, $login, $repository, $body, $token);
        if ($issue->assignee->login !== $user) {
            throw new \LogicException("Call succeeded, but '{$user}' was not assigned.");
        }
        return true;
    }

    /**
     * Drop back to backlog!
     */
    public function dropIssue($issue, $login, $repository, $token)
    {
        $body           = new \StdClass;
        $body->assignee = "";

        $issue = $this->updateIssue($issue, $login, $repository, $body, $token);
        return true;
    }

    /**
     * @param array $params An array with 'code' and 'state'.
     *
     * @return string
     */
    public function getAccessToken(array $params)
    {
        $params['client_id']     = $this->id;
        $params['client_secret'] = $this->secret;

        $client = $this->getClient();

        $client->setUrl('https://github.com/login/oauth/access_token')
            ->setMethod(\HTTP_Request2::METHOD_POST)
            ->addPostParameter($params);

        $response = $this->doRequest($client);
        $data     = $this->parseResponse($response);

        return $data->access_token;
    }

    /**
     * Populate in {@link self::parseResponse()}.
     *
     * @return \stdClass
     */
    public function getCurrentRateLimit()
    {
        return $this->rateLimit;
    }

    /**
     * Get issues by state.
     *
     * @param string $login      Name of the organization or a user.
     * @param string $repository Name of the repository.
     * @param string $token      The access token.
     * @param string $state      'open' or 'closed'.
     * @param mixed  $milestone  Optionally a milestone.
     *
     * @return array
     */
    public function getIssues($login, $repository, $token, $state = 'open', $milestone = null)
    {
        $client = $this->getClient();

        $url = sprintf(
            "https://api.github.com/repos/%s/%s/issues?state=%s",
            $login, $repository, $state
        );

        if (null !== $milestone && is_numeric($milestone) && $milestone > 0) {
            $url .= sprintf('&milestone=%s', $milestone);
        }

        $client->setUrl($url)->setMethod(\HTTP_Request2::METHOD_GET);

        $response = $this->doRequest($client, $token);

        try {
            $issues = $this->parseResponse($response);
        } catch (\DomainException $e) {
            $issues = array();
        }
        return $issues;
    }

    /**
     * Creates the URL for the redirect to Github to authenticate the
     * user and to request access to their profile.
     *
     * @param string $host  The hostname for the redirect URL.
     * @param string $scope 'repo' by default
     *
     * @return array
     */
    public function getLoginUrl($host, $scope = 'repo')
    {
        throw new \LogicException("Please implement this yourself.");

        /*
        $params = array(
            'client_id'    => $this->id,
            'redirect_url' => sprintf('http://%s/handshake', $host),
            'scope'        => $scope,
            'state'        => sha1(sprintf('github-provider-%d', time())),
        );
        */

        return sprintf(
            'https://github.com/login/oauth/authorize?%s',
            http_build_query($params)
        );
    }

    /**
     * Get milestones.
     *
     * @param string $login      Name of the organization or a user.
     * @param string $repository Name of the repository.
     * @param string $token      The access token.
     *
     * @return array
     */
    public function getMilestones($login, $repository, $token)
    {
        $client = $this->getClient();

        $client->setUrl("https://api.github.com/repos/$login/$repository/milestones");

        $response = $this->doRequest($client, $token);

        try {
            $milestones = $this->parseResponse($response);
        } catch (\DomainException $e) {
            $milestones = array();
        }

        return $milestones;
    }

    /**
     * Retrieve organizations for the token.
     *
     * @param string $token The access token.
     *
     * @return array
     */
    public function getOrganizations($token)
    {
        $client = $this->getClient();

        $client->setUrl('https://api.github.com/user/orgs')
            ->setMethod(\HTTP_Request2::METHOD_GET);

        $response = $this->doRequest($client, $token);

        $organizations = $this->parseResponse($response);
        return $organizations;
    }

    /**
     * Return all repositories for the organization and the token.
     *
     * @param string $organization The name of the Github organization.
     * @param string $token        The access token.
     *
     * @return array
     */
    public function getRepositories($organization, $token, $type = 'all')
    {
        $client = $this->getClient();

        $url = sprintf(
            "https://api.github.com/orgs/%s/repos?type=%s",
            $organization,
            $type
        );

        $client->setUrl($url)->setMethod(\HTTP_Request2::METHOD_GET);

        $repositories    = array();
        $allRepositories = $this->getMultiple($client, $token);

        $helper = new Helper;

        $repositories['private'] = array_filter($allRepositories, array($helper, 'findPrivateRepositories'));
        $repositories['public']  = array_filter($allRepositories, array($helper, 'findPublicRepositories'));

        return $repositories;
    }

    /**
     * @param string $token The access token.
     *
     * @return \stdClass
     */
    public function getUser($token)
    {
        $client = $this->getClient();

        $client->setUrl('https://api.github.com/user')
            ->setMethod(\HTTP_Request2::METHOD_GET);

        $response = $this->doRequest($client, $token);

        $user        = $this->parseResponse($response);
        $user->token = $token;

        return $user;
    }

    /**
     * Get repositories of the currently logged in user.
     *
     * @param string $token
     *
     * @return array
     */
    public function getUserRepositories($token)
    {
        $client = $this->getClient();

        $client->setUrl("https://api.github.com/user/repos")
            ->setMethod(\HTTP_Request2::METHOD_GET);

        $repositories    = array();
        $allRepositories = $this->getMultiple($client, $token);

        $helper = new Helper;

        $repositories['private'] = array_filter($allRepositories, array($helper, 'findPrivateRepositories'));
        $repositories['public']  = array_filter($allRepositories, array($helper, 'findPublicRepositories'));

        return $repositories;
    }
}
