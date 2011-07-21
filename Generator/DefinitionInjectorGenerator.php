<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\DiExtraBundle\Generator;

use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Generates lightweight code for injecting a single definition.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class DefinitionInjectorGenerator
{
    public function generate(Definition $def)
    {
        $code = "<?php return function(\$container) {\n";

        if ($file = $def->getFile()) {
            $code .= sprintf("    require_once %s;\n", var_export($file, true));
        }

        $code .= "    \$instance = new \\{$def->getClass()}{$this->dumpArguments($def->getArguments())};\n";

        foreach ($def->getMethodCalls() as $call) {
            list($method, $arguments) = $call;
            $code .= "    \$instance->$method{$this->dumpArguments($arguments)};\n";
        }

        $ref = new \ReflectionClass($def->getClass());
        foreach ($def->getProperties() as $property => $value) {
            $refProperty = $this->getReflectionProperty($ref, $property);

            if ($refProperty->isPublic()) {
                $code .= "    \$instance->$property = {$this->dumpValue($value)};\n";
            } else {
                $code .= sprintf("    \$refProperty = new \ReflectionProperty(%s, %s);\n", var_export($refProperty->getDeclaringClass()->getName(), true), var_export($property, true));
                $code .= "    \$refProperty->setAccessible(true);\n";
                $code .= "    \$refProperty->setValue(\$instance, {$this->dumpValue($value)});\n";
            }
        }

        // FIXME: Add support for configurators (also needs to be added to AnnotationDriver)

        $code .= "\n    return \$instance;\n";
        $code .= "};\n";

        return $code;
    }

    private function getReflectionProperty($ref, $property)
    {
        $origClass = $ref->getName();
        while (!$ref->hasProperty($property) && false !== $ref = $ref->getParentClass());

        if (!$ref->hasProperty($property)) {
            throw new \RuntimeException(sprintf('Could not find property "%s" anywhere in class "%s" or one of its parents.', $property, $origName));
        }

        return $ref->getProperty($property);
    }

    private function dumpArguments(array $arguments)
    {
        $code = '(';

        foreach ($arguments as $argument) {
            if (!$first) {
                $code .= ', ';
            }
            $first = false;

            $code .= $this->dumpValue($argument);
        }

        return $code.')';
    }

    private function dumpValue($value)
    {
        if (is_array($value)) {
            $code = 'array(';

            $first = true;
            foreach ($value as $k => $v) {
                if (!$first) {
                    $code .= ', ';
                }
                $first = false;

                $code .= sprintf('%s => %s', var_export($k, true), $this->dumpValue($v));
            }

            return $code.')';
        } else if ($value instanceof Reference) {
            if ('service_container' === (string) $value) {
                return '$container';
            }

            return sprintf('$container->get(%s, %d)', var_export((string) $value, true), $value->getInvalidBehavior());
        } else if ($value instanceof Parameter) {
            return sprintf('$container->getParameter(%s)', var_export((string) $value, true));
        } else if (is_scalar($value) || null === $value) {
            // we do not support embedded parameters
            if (is_string($value) && '%' === $value[0] && '%' !== $value[1]) {
                return sprintf('$container->getParameter(%s)', var_export(substr($value, 1, -1), true));
            }

            return var_export($value, true);
        }

        throw new \RuntimeException(sprintf('Found unsupported value of type %s during definition injector generation: "%s"', gettype($value), json_encode($value)));
    }
}