<?php

namespace App\Core;

class Router
{
    private $routes = [];

    // Corrected: Now accepts the handler array correctly from shorthand methods
    public function add($method, $path, $handler)
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'controller' => $handler[0], // Full namespace class name
            'action'     => $handler[1]  // Method name
        ];
    }

    // Shorthand methods fixed to pass only 2 arguments to add()
    public function get($path, $handler)    { $this->add('GET', $path, $handler); }
    public function post($path, $handler)   { $this->add('POST', $path, $handler); }
    public function patch($path, $handler)  { $this->add('PATCH', $path, $handler); }
    public function put($path, $handler)    { $this->add('PUT', $path, $handler); }
    public function delete($path, $handler) { $this->add('DELETE', $path, $handler); }

    public function dispatch($uri, $method)
    {
        $path = parse_url($uri, PHP_URL_PATH);

        // 1. Clean URL
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        
        // If the request path starts with the script dir (e.g. /project/public/api...), strip it
        if (strpos($path, $scriptDir) === 0) {
            $path = substr($path, strlen($scriptDir));
        } 
        // If not, maybe we are accessing via root but index.php is in /public (Rewrite Rule case)
        // e.g. ScriptDir: /project/public, Path: /project/api/auth
        else {
             $parentDir = dirname($scriptDir); // /project
             if ($parentDir !== '/' && $parentDir !== '.' && strpos($path, $parentDir) === 0) {
                 $path = substr($path, strlen($parentDir));
             }
        }

        $path = '/' . ltrim($path, '/');

        // 2. Search for route
        foreach ($this->routes as $route) {
            $pattern = "#^" . preg_replace('/\{id\}/', '(\d+)', $route['path']) . "$#";

            if ($route['method'] === $method && preg_match($pattern, $path, $matches)) {
                
                // 3. Instantiate Controller
                // Since you require files in index.php, we just call the class
                $controllerName = $route['controller']; 
                $actionName = $route['action'];

                if (!class_exists($controllerName)) {
                    http_response_code(500);
                    echo json_encode(["success" => false, "message" => "Controller $controllerName not found"]);
                    return;
                }

                $controller = new $controllerName();

                // 4. Call action
                $id = isset($matches[1]) ? $matches[1] : null;
                $controller->$actionName($id);
                return;
            }
        }

        // 5. 404
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "404 Not Found",
            "path" => $path
        ]);
    }
}

?>