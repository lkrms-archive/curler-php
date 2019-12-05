<?php

namespace Lkrms\Curler;
use Exception;

class Curler
{
    private $BaseUrl;

    private $Headers;

    private $LastResponseHeaders;

    private $Debug = false;

    private $NoNumericKeys = false;

    private static $Curl;

    private static $ResponseHeaders;

    public function __construct($baseUrl, CurlerHeader $headers)
    {
        if (is_null($headers) || ! ($headers instanceof CurlerHeader))
        {
            throw new Exception('Invalid headers.');
        }

        $this->BaseUrl  = $baseUrl;
        $this->Headers  = $headers;

        if ( ! is_resource(self::$Curl))
        {
            self::$Curl = curl_init();
            $this->Reset();
        }
    }

    private function Reset()
    {
        curl_reset(self::$Curl);

        // don't send output to browser
        curl_setopt(self::$Curl, CURLOPT_RETURNTRANSFER, true);

        // fail if HTTP response code is >=400, rather than returning the error page
        curl_setopt(self::$Curl, CURLOPT_FAILONERROR, true);

        // collect response headers
        curl_setopt(self::$Curl, CURLOPT_HEADERFUNCTION,

        function ($curl, $header)
        {
            $split = explode(':', $header, 2);

            if (count($split) == 2)
            {
                list ($name, $value) = $split;

                // tidy up any stray spaces
                self::$ResponseHeaders[trim($name)] = trim($value);
            }

            return strlen($header);
        }

        );

        // clear any previous response headers
        self::$ResponseHeaders = array();
    }

    private function HttpBuildQuery($queryData)
    {
        $query = http_build_query($queryData);

        if ($this->NoNumericKeys)
        {
            $query = preg_replace('/(^|&)([^=]*%5B)[0-9]+(%5D[^=]*)/', '$1$2$3', $query);
        }

        return $query;
    }

    private function Initialise($requestType = 'GET', array $queryString = null)
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

                // nothing to do -- GET is the default
                break;

            case 'POST':

                curl_setopt(self::$Curl, CURLOPT_POST, true);

                break;

            default:

                // allows DELETE, PATCH etc.
                curl_setopt(self::$Curl, CURLOPT_CUSTOMREQUEST, $requestType);
        }
    }

    private function AddData( array $data = null, $asJson = true)
    {
        if ($asJson)
        {
            if ( ! is_null($data))
            {
                $this->Headers->SetHeader('Content-Type', 'application/json');
            }

            curl_setopt(self::$Curl, CURLOPT_POSTFIELDS, is_null($data) ? '' : json_encode($data));
        }
        else
        {
            $query = '';

            if ( ! is_null($data))
            {
                $query = $this->HttpBuildQuery($data);
            }

            curl_setopt(self::$Curl, CURLOPT_POSTFIELDS, $query);
        }
    }

    private function Execute()
    {
        // add headers for authentication etc.
        curl_setopt(self::$Curl, CURLOPT_HTTPHEADER, $this->Headers->GetHeaders());

        if ($this->Debug)
        {
            curl_setopt(self::$Curl, CURLINFO_HEADER_OUT, true);
        }

        // execute the request
        $result = curl_exec(self::$Curl);

        if ($result === false)
        {
            throw new Exception('cURL error: ' . curl_error(self::$Curl) . ($this->Debug ? ' ' . json_encode(curl_getinfo(self::$Curl)) : ''));
        }

        return $result;
    }

    private function Close()
    {
        $this->LastResponseHeaders = self::$ResponseHeaders;
        $this->Reset();
    }

    public function GetBaseUrl()
    {
        return $this->BaseUrl;
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

    public function Get( array $queryString = null)
    {
        $this->Initialise('GET', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function GetJson( array $queryString = null)
    {
        return json_decode($this->Get($queryString), true);
    }

    public function Post( array $data = null, array $queryString = null)
    {
        $this->Initialise('POST', $queryString);

        if ( ! is_null($data))
        {
            $this->AddData($data);
        }

        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function PostJson( array $data = null, array $queryString = null)
    {
        return json_decode($this->Post($data, $queryString), true);
    }

    public function Put( array $data = null, array $queryString = null)
    {
        $this->Initialise('PUT', $queryString);
        $this->AddData($data, false);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function PutJson( array $data = null, array $queryString = null)
    {
        return json_decode($this->Put($data, $queryString), true);
    }

    public function Patch( array $data = null, array $queryString = null)
    {
        $this->Initialise('PATCH', $queryString);
        $this->AddData($data);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function PatchJson( array $data = null, array $queryString = null)
    {
        return json_decode($this->Patch($data, $queryString), true);
    }

    public function Delete( array $queryString = null)
    {
        $this->Initialise('DELETE', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function DeleteJson( array $queryString = null)
    {
        return json_decode($this->Delete($queryString), true);
    }

    public function GetLastResponseHeaders()
    {
        return $this->LastResponseHeaders;
    }

    /**
     * Follows HTTP "Link" headers to retrieve and merge paged JSON data.
     *
     * @param array $queryString
     * @return array All returned entities.
     */
    public function GetAllLinked( array $queryString = null)
    {
        $this->Initialise('GET', $queryString);
        $entities  = array();
        $nextUrl   = null;

        do
        {
            if ($nextUrl)
            {
                curl_setopt(self::$Curl, CURLOPT_URL, $nextUrl);
                self::$ResponseHeaders  = array();
                $nextUrl                = null;
            }

            $result = json_decode($this->Execute(), true);

            // collect data from response and move on to next page
            $entities = array_merge($entities, $result);

            if (isset(self::$ResponseHeaders['Link']) && preg_match('/<([^>]+)>;\s*rel=([\'"])next\2/', self::$ResponseHeaders['Link'], $matches))
            {
                $nextUrl = $matches[1];
            }
        }
        while ($nextUrl);

        $this->Close();

        return $entities;
    }

    /**
     * Follows $result['links']['next'] to retrieve and merge paged JSON data.
     *
     * @param string $entityName Data is retrieved from $result[$entityName].
     * @param array $queryString
     * @return array All returned entities.
     */
    public function GetAllLinkedByEntity($entityName, array $queryString = null)
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

        $this->Close();

        return $entities;
    }
}

