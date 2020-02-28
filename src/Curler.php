<?php

declare(strict_types=1);

namespace Lkrms\Curler;
use Exception;

class Curler
{
    private $BaseUrl;

    private $Headers;

    private $LastCurlInfo;

    private $LastResponse;

    private $LastResponseCode;

    private $LastResponseHeaders;

    private $Debug = false;

    private $NoNumericKeys = false;

    private static $Curl;

    private static $ResponseHeaders;

    public function __construct(string $baseUrl, CurlerHeader $headers)
    {
        $this->BaseUrl  = $baseUrl;
        $this->Headers  = $headers;

        if ( ! is_resource(self::$Curl))
        {
            self::$Curl = curl_init();

            // don't send output to browser
            curl_setopt(self::$Curl, CURLOPT_RETURNTRANSFER, true);

            // collect response headers
            curl_setopt(self::$Curl, CURLOPT_HEADERFUNCTION,

            function ($curl, $header)
            {
                $split = explode(':', $header, 2);

                if (count($split) == 2)
                {
                    list ($name, $value) = $split;

                    // header field names are case-insensitive
                    $name   = strtolower($name);
                    $value  = trim($value);
                    self::$ResponseHeaders[$name] = $value;
                }

                return strlen($header);
            }

            );
        }
    }

    private function HttpBuildQuery($queryData) : string
    {
        $query = http_build_query($queryData);

        if ($this->NoNumericKeys)
        {
            $query = preg_replace('/(^|&)([^=]*%5B)[0-9]+(%5D[^=]*)/', '$1$2$3', $query);
        }

        return $query;
    }

    private function Initialise($requestType, ? array $queryString)
    {
        $query = '';

        if (is_array($queryString))
        {
            $query = $this->HttpBuildQuery($queryString);

            if ($query)
            {
                $query = '?' . $query;
            }
        }

        curl_setopt(self::$Curl, CURLOPT_URL, $this->BaseUrl . $query);

        switch ($requestType)
        {
            case 'GET':

                curl_setopt(self::$Curl, CURLOPT_CUSTOMREQUEST, null);
                curl_setopt(self::$Curl, CURLOPT_HTTPGET, true);

                break;

            case 'POST':

                curl_setopt(self::$Curl, CURLOPT_CUSTOMREQUEST, null);
                curl_setopt(self::$Curl, CURLOPT_POST, true);

                break;

            default:

                // allows DELETE, PATCH etc.
                curl_setopt(self::$Curl, CURLOPT_CUSTOMREQUEST, $requestType);
        }

        $this->Headers->UnsetHeader('Content-Type');

        // in debug mode, collect request headers
        curl_setopt(self::$Curl, CURLINFO_HEADER_OUT, $this->Debug);
    }

    private function SetData( ? array $data, bool $asJson)
    {
        $query    = '';
        $hasFile  = false;

        if ( ! is_null($data))
        {
            array_walk_recursive($data,

            function ( & $item, $key) use ( & $hasFile)
            {
                if ($item instanceof CurlerFile)
                {
                    $item     = $item->GetCurlFile();
                    $hasFile  = true;
                }
            }

            );

            if ($hasFile)
            {
                $query = $data;
            }
            elseif ($asJson)
            {
                $this->Headers->SetHeader('Content-Type', 'application/json');
                $query = json_encode($data);
            }
            else
            {
                $query = $this->HttpBuildQuery($data);
            }

            curl_setopt(self::$Curl, CURLOPT_POSTFIELDS, $query);
        }
    }

    private function Execute() : string
    {
        // add headers for authentication etc.
        curl_setopt(self::$Curl, CURLOPT_HTTPHEADER, $this->Headers->GetHeaders());

        // clear any previous response headers
        self::$ResponseHeaders = array();

        // execute the request
        $result = curl_exec(self::$Curl);

        // save transfer information
        $this->LastCurlInfo         = curl_getinfo(self::$Curl);
        $this->LastResponseHeaders  = self::$ResponseHeaders;

        if ($result === false)
        {
            $this->LastResponse      = null;
            $this->LastResponseCode  = null;
            throw new CurlerException('cURL error: ' . curl_error(self::$Curl), $this);
        }

        $this->LastResponse      = $result;
        $this->LastResponseCode  = (int)curl_getinfo(self::$Curl, CURLINFO_RESPONSE_CODE);

        if ($this->LastResponseCode >= 400)
        {
            throw new CurlerException("HTTP error " . $this->LastResponseHeaders [
                'status'
            ]??$this->LastResponseCode, $this);
        }

        return $result;
    }

    public function GetBaseUrl() : string
    {
        return $this->BaseUrl;
    }

    public function GetHeaders() : CurlerHeader
    {
        return $this->Headers;
    }

    public function GetDebug() : bool
    {
        return $this->Debug;
    }

    public function GetNoNumericKeys() : bool
    {
        return $this->NoNumericKeys;
    }

    public function EnableDebug()
    {
        $this->Debug = true;
    }

    public function DisableDebug()
    {
        $this->Debug = false;
    }

    public function EnableNumericKeys()
    {
        $this->NoNumericKeys = false;
    }

    public function DisableNumericKeys()
    {
        $this->NoNumericKeys = true;
    }

    public function Get( array $queryString = null) : string
    {
        $this->Initialise('GET', $queryString);

        return $this->Execute();
    }

    public function GetJson( array $queryString = null) : ? array
    {
        return json_decode($this->Get($queryString), true);
    }

    public function Post( array $data = null, array $queryString = null, bool $dataAsJson = true) : string
    {
        $this->Initialise('POST', $queryString);
        $this->SetData($data, $dataAsJson);

        return $this->Execute();
    }

    public function PostJson( array $data = null, array $queryString = null, bool $dataAsJson = true) : ? array
    {
        return json_decode($this->Post($data, $queryString, $dataAsJson), true);
    }

    public function RawPost(string $data, string $contentType, array $queryString = null) : string
    {
        $this->Initialise('POST', $queryString);
        $this->Headers->SetHeader('Content-Type', $contentType);
        curl_setopt(self::$Curl, CURLOPT_POSTFIELDS, $data);

        return $this->Execute();
    }

    public function RawPostJson(string $data, string $contentType, array $queryString = null) : ? array
    {
        return json_decode($this->RawPost($data, $contentType, $queryString), true);
    }

    public function Put( array $data = null, array $queryString = null, bool $dataAsJson = true) : string
    {
        $this->Initialise('PUT', $queryString);
        $this->SetData($data, $dataAsJson);

        return $this->Execute();
    }

    public function PutJson( array $data = null, array $queryString = null, bool $dataAsJson = true) : ? array
    {
        return json_decode($this->Put($data, $queryString, $dataAsJson), true);
    }

    public function Patch( array $data = null, array $queryString = null, bool $dataAsJson = true) : string
    {
        $this->Initialise('PATCH', $queryString);
        $this->SetData($data, $dataAsJson);

        return $this->Execute();
    }

    public function PatchJson( array $data = null, array $queryString = null, bool $dataAsJson = true) : ? array
    {
        return json_decode($this->Patch($data, $queryString, $dataAsJson), true);
    }

    public function Delete( array $queryString = null) : string
    {
        $this->Initialise('DELETE', $queryString);

        return $this->Execute();
    }

    public function DeleteJson( array $queryString = null) : ? array
    {
        return json_decode($this->Delete($queryString), true);
    }

    public function GetLastCurlInfo() : ? array
    {
        return $this->LastCurlInfo;
    }

    public function GetLastResponse() : ? string
    {
        return $this->LastResponse;
    }

    public function GetLastResponseCode() : ? int
    {
        return $this->LastResponseCode;
    }

    public function GetLastResponseHeaders() : ? array
    {
        return $this->LastResponseHeaders;
    }

    /**
     * Follows HTTP "Link" headers to retrieve and merge paged JSON data.
     *
     * @param array $queryString
     * @return array All returned entities.
     */
    public function GetAllLinked( array $queryString = null) : array
    {
        $this->Initialise('GET', $queryString);
        $entities  = array();
        $nextUrl   = null;

        do
        {
            if ($nextUrl)
            {
                curl_setopt(self::$Curl, CURLOPT_URL, $nextUrl);
                $nextUrl = null;
            }

            $result = json_decode($this->Execute(), true);

            // collect data from response and move on to next page
            $entities = array_merge($entities, $result);

            if (isset($this->LastResponseHeaders [
                'link'
            ]) && preg_match('/<([^>]+)>;\s*rel=([\'"])next\2/', $this->LastResponseHeaders [
                'link'
            ], $matches))
            {
                $nextUrl = $matches[1];
            }
        }
        while ($nextUrl);

        return $entities;
    }

    /**
     * Follows $result['links']['next'] to retrieve and merge paged JSON data.
     *
     * @param string $entityName Data is retrieved from $result[$entityName].
     * @param array $queryString
     * @return array All returned entities.
     */
    public function GetAllLinkedByEntity($entityName, array $queryString = null) : array
    {
        $this->Initialise('GET', $queryString);
        $entities  = array();
        $nextUrl   = null;

        do
        {
            if ($nextUrl)
            {
                curl_setopt(self::$Curl, CURLOPT_URL, $nextUrl);
                $nextUrl = null;
            }

            $result = json_decode($this->Execute(), true);

            // collect data from response and move on to next page
            $entities = array_merge($entities, $result[$entityName]);

            if (isset($result['links']['next']))
            {
                $nextUrl = $result['links']['next'];
            }
        }
        while ($nextUrl);

        return $entities;
    }

    public function GetByGraphQL(string $query, array $variables = null, bool $paginated = false) : array
    {
        if ($paginated && ! (($variables['first']??null) && array_key_exists('after', $variables)))
        {
            throw new CurlerException('$first and $after variables are required for pagination', $this);
        }

        $entities   = array();
        $nextQuery  = array(
            'query'     => $query,
            'variables' => $variables,
        );

        do
        {
            $result       = $this->PostJson($nextQuery);
            $nextQuery    = null;
            $cursor       = null;
            $entityNames  = array_keys($result['data']?? []);
            $entityName   = array_shift($entityNames);

            if ( ! $entityName)
            {
                throw new CurlerException('no data returned', $this);
            }

            $data = array_map(

            function ($entity) use ( & $cursor)
            {
                $cursor = $entity['cursor']??null;
                unset($entity['cursor']);

                return $entity['node']??$entity;
            }

            , $result['data'][$entityName]['edges']?? []);
            $entities = array_merge($entities, $data);

            if ($paginated && $cursor && ($result['data'][$entityName]['pageInfo']['hasNextPage']??false))
            {
                $variables['after']  = $cursor;
                $nextQuery           = array(
                    'query'     => $query,
                    'variables' => $variables,
                );
            }
        }
        while ($nextQuery);

        return $entities;
    }
}

