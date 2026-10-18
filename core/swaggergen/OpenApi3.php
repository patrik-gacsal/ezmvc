<?php
namespace swaggergen;

class OpenApi3 {
    public $openapi        = '3.0.0';
    public $info, $servers = [], $paths = [], $components, $tags = [];

    public function __construct() {
        $this->components = new Components();
    }

    public function setInfo($title, $desc, $version) {
        $this->info              = new Info();
        $this->info->description = $desc;
        $this->info->title       = $title;
        $this->info->version     = $version;
    }

    public function addServer($url, $desc) {
        $server              = new Server();
        $server->description = $desc;
        $server->url         = $url;
        $this->servers[]     = $server;
    }

    public function addMethod($path, $method, $desc) {
        $m          = new Method();
        $m->summary = strlen($desc) > 100
            ? substr($desc, 0, 100) . '...'
            : $desc;
        $m->description = $desc;
        $this->paths[$path][$method] = $m;
    }

    public function addMethodParam($path, $method, $param) {
        $this->paths[$path][$method]->parameters[] = $param;
    }

    public function addMethodRequest($path, $method, $request) {
        $this->paths[$path][$method]->requestBody          = new \stdClass();
        $this->paths[$path][$method]->requestBody->content = $request;
    }

    public function addMethodResponse($path, $method, $response, $code = 'default') {
        $this->paths[$path][$method]->responses[$code]              = new \stdClass();
        $this->paths[$path][$method]->responses[$code]->content     = $response;
        $this->paths[$path][$method]->responses[$code]->description = '';
    }

    public function addMethodTag($path, $method, $tag) {
        $this->paths[$path][$method]->tags[] = $tag;
    }

    public function setMethodSecurity($path, $method, $auth) {
        $this->paths[$path][$method]->security = $auth
            ? [[$auth => []]]
            : [];
    }

    public function addSchema($name, $schema) {
        $this->components->schemas[$name] = $schema;
    }

    public function addObject($obj, $decorators = null, $name = null) {
        if (! is_object($obj)) {
            $obj = new $obj();
        }
        if (! is_object($obj)) {
            return;
        }
        $props = [];
        if ($decorators) {
            foreach ($decorators as $d) {
                $words = preg_split('/\s/', $d, 4);
                if ($words[0] !== '@property' || count($words) < 3) {
                    continue;
                }
                if (count($words) < 4) {
                    $words[3] = '';
                }
                $props[substr($words[2], 1)] = new Property($words[1], null, $words[3]);
            }
        }
        $cn = $name ?? get_class($obj);
        $s  = new Schema();
        foreach ((new \ReflectionClass($obj))->getProperties(\ReflectionProperty::IS_PUBLIC) as $rp) {
            $key  = $rp->getName();
            $rt   = $rp->getType();
            $type = $rt instanceof \ReflectionNamedType ? $rt->getName() : 'string';
            if ($type === 'array') {
                $type = \EzMvc\Core::arrayVarType($rp) ?? 'array';
            }
            $s->properties[$key] = isset($props[$key])
                ? createProperty($key, $props[$key]->type, $props[$key]->description)
                : createProperty($key, $type, null);
        }
        foreach($props as $key => $prop) {
            if(!isset($s->properties[$key]))
                $s->properties[$key] = createProperty($key, $prop->type, $prop->description);
        }
        $this->addSchema($cn, $s);
    }

    public function addTag($name, $desc = '') {
        foreach ($this->tags as $t) {
            if ($t->name === $name) {
                return;
            }
        }
        $tag              = new Tag();
        $tag->name        = $name;
        $tag->description = $desc;
        $this->tags[]     = $tag;
    }

    /**
     * Recursively drops null and [] members, so the spec omits empty fields. An
     * object's `security` is kept even when [], where it means "no auth".
     */
    public function cleanUp(&$node = null): void {
        $node ??= $this;
        $isObject = is_object($node);
        foreach ($node as $key => $value) {
            if ($isObject && $key === 'security') {
                continue;
            }
            if ($value === null || $value === []) {
                if ($isObject) {
                    unset($node->$key);
                } else {
                    unset($node[$key]);
                }
            } elseif (is_array($value) || is_object($value)) {
                if ($isObject) {
                    $this->cleanUp($node->$key);
                } else {
                    $this->cleanUp($node[$key]);
                }
            }
        }
    }
}

class Info {
    public $title, $description, $version;
}

class Server {
    public $url, $description;
}

class Tag {
    public $name, $description;
}

class Method {
    public $summary, $description, $parameters, $requestBody, $responses, $tags = [];
}

/**
 * Body content for one schema. A list ($isList) is expressed the way OpenAPI
 * means it — an inline array whose items $ref the element schema — so the
 * element schema is the only one that has to exist.
 */
function createContent($schema, bool $isList = false) {
    $body = in_array($schema, ['string'])
        ? ['type' => $schema]
        : ['$ref' => '#/components/schemas/' . $schema];
    if ($isList) {
        $body = ['type' => 'array', 'items' => $body];
    }
    return ['application/json' => ['schema' => $body]];
}

class Parameter {
    public $name, $in = 'path', $description, $required = true, $schema;
    public function __construct($name, $in = 'path', $type = 'string', $required = true, $desc = null) {
        $this->name         = $name;
        $this->in           = $in;
        $this->required     = $required;
        $this->description  = $desc;
        $this->schema       = new \stdClass();
        $this->schema->type = $type === 'int' ? 'integer' : $type;
    }
}

class Components {
    public $schemas = [], $securitySchemes;
    public function __construct() {
        $this->securitySchemes = ['bearerAuth' => new BearerAuth(), 'apikeyAuth' => new ApikeyAuth()];
    }
}

class BearerAuth {
    public $type = 'http', $scheme = 'bearer', $bearerFormat = 'JWT';
}

class ApikeyAuth {
    public $in = 'header', $type = 'apiKey', $name = 'APIKEY';
}

class Schema {
    public $type = 'object', $required = [], $properties = [];
}

/** A property schema for a PHP type: a scalar, a schema class ($ref), `T[]` of either at any depth, or 'array' (any items). */
function createProperty($name, $type, $description = null) {
    if (substr($type, -2) === '[]' || $type === 'array') {
        $prop        = new Property('array', null, $description);
        $prop->items = $type === 'array' ? new \stdClass() : createProperty($name, substr($type, 0, -2));
        return $prop;
    }
    $type = ['float' => 'number', 'double' => 'number', 'int' => 'integer', 'bool' => 'boolean'][$type] ?? $type;
    if (in_array($type, ['integer', 'string', 'number', 'boolean'])) {
        return new Property($type, null, $description);
    }
    if ($type[0] >= 'A' && $type[0] <= 'Z') {
        return ['$ref' => '#/components/schemas/' . $type, 'description' => $description];
    }
    return new Property($type);
}

class Property {
    public $type, $format, $description = '', $items;
    public function __construct($type = 'string', $format = '', $description = null) {
        $this->type        = $type;
        $this->format      = $format;
        $this->description = $description;
    }
}
