<?php
/**
 * Фильтр и проверка значений
 *
 * - Используются классом \boolive\values\Values в соответствии с правилами \boolive\values\Rule для непосредственной
 *   проверки и фильтра значений.
 * - Для фильтра и проверки в соответствии с правилом применяется универсальный метод Check::Filter(),
 *   которым последовательно вызываются указанные в правиле соответствующие методы фильтров.
 *   Вызываются одноименные фильтрам методы класса Check, если их нет, то вызовится магический методы, которым
 *   сгенерируется событие для вызова внешнего метода фильтра.
 * - Если исходное значение отличается от отфильтрованного или не подлежит фильтру, то создаётся ошибка с кодом,
 *   соответствующим названию примененного фильтра, например "max".
 * - Выполнение фильтров прекращается при возникновении первой ошибки. Но если специальным фильтром ignore определены
 *   игнорируемые ошибки, то выполнение следующих фильтров продолжается без создания ошибки, если текущая ошибка в
 *   списке игнорируемых.
 * - Если в правиле определено значение по умолчанию и имеется ошибка после всех проверок, то ошибка игнорируется
 *   и возвращается значение по умолчанию.
 * - В классе реализованы стандартные фильтры. Если в правиле определен нестандартный фильтр, то будет
 *   вызвано событие "Check::Filter_{name}", где {name} - название фильтра. Через событие будет предпринята попытка
 *   вызова внешней функции фильтра. Как создать свой фильтр написано в комментариях класса Rule.
 * - При проверки сложных структур, например массивов, объект ошибки будет вложенным. Вложенность ошибки соответствует
 *   вложенности правила.
 * - Ошибка - это объект класса \boolive\errors\Error, являющийся наследником исключений. При возникновении ошибки
 *   исключения не выкидываются, а возвращается созданный объект исключения.
 *
 * @link http://boolive.ru/createcms/rules-for-filter (про правила и создание нового фильтра)
 * @version 2.1
 * @author Vladimir Shestakov <boolive@yandex.ru>
 */
namespace boolive\values;

use boolive\events\Events,
    boolive\data\Entity,
    boolive\data\Data2,
    boolive\errors\Error;

class Check
{
    /**
     * Универсальный фильтр значения по правилу
     * @param mixed $value Значение для проверки и фильтра
     * @param null|\boolive\values\Rule $rule Объект правила
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @return mixed
     */
    static function filter($value, $rule, &$error = null)
    {
        $result = null;
        if ($rule instanceof Rule){
            $filters = $rule->getFilters();
            // Подготовка специальных Фильтров. Удаление из общего списка обработки
            if (isset($filters['required'])) unset($filters['required']);
            if (isset($filters['default']))	unset($filters['default']);
            if (isset($filters['ignore'])){
                $ignore = $filters['ignore'];
                if (sizeof($ignore) == 1 && is_array($ignore[0])) $ignore = $ignore[0];
                unset($filters['ignore']);
            }else{
                $ignore = array();
            }
            // Фильтр значений
            foreach ($filters as $filter => $args){
                $value = self::$filter($value, $error, $rule);
                if ($error){
                    if ($ignore && in_array($error->getCode(), $ignore)){
                        $error = null;
                    }else{
                        break;
                    }
                }
            }
            // Значение по умолчанию, если определено и имеется ошибка
            if ($error && isset($rule->default)){
                $error = null;
                $value = $rule->default[0];
            }
        }
        return $value;
    }

    /**
     * Вызов внешнего фильтра
     * @param string $method Названия фильтра
     * @param array $args Аргументы для метода фильтра
     * @return mixed
     */
    static function __callStatic($method, $args)
    {
        $result = Events::trigger('Check::filter_'.$method, $args);
        if ($result->count > 0){
            return $result->result;
        }else{
            return $args[0];
        }
    }

    /**
     * Проверка и фильтр логического значения
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return bool
     */
    static function bool($value, &$error, Rule $rule)
    {
        if ($value===true || $value===false){
            return $value;
        }
        if (is_string($value)){
            return !in_array(mb_strtolower($value), array('false', '', '0'));
        }
        if (is_scalar($value)){
            return (bool)$value;
        }
        $error = new Error('Должно быть логическим (true/false, 1/0)', 'bool');
        return (bool)$value;
    }

    /**
     * Проверка и фильтр целого числа в диапазоне от -2147483648 до 2147483647
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return int|string
     */
    static function int($value, &$error, Rule $rule)
    {
        if (is_string($value)){
            $value = str_replace(' ', '', $value);
        }
        if (!is_bool($value) && ((is_scalar($value) && preg_match('/^[-\+]?[0-9]+$/', strval($value)) == 1))){
            $result = doubleval($value);
            if (-PHP_INT_MAX < $value && $value < PHP_INT_MAX ){
                $result = intval($value);
            }
            return $result;
        }
        $error = new Error('Некорректное целое число', 'int');
        return is_object($value)?1:intval($value);
    }

    /**
     * Проверка и фильтр действительного числа в диапазоне от -1.7976931348623157E+308 до 1.7976931348623157E+308
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return int|string
     */
    static function double($value, &$error, Rule $rule)
    {
        if (is_string($value)){
            $value = str_replace(' ', '', $value);
            $value = str_replace(',', '.', $value);
        }
        if (is_numeric($value)){
            return doubleval($value);
        }
        $error = new Error('Некорректное число', 'double');
        return is_object($value)?1:doubleval($value);
    }

    /**
     * Проверка и фильтр строки
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return string
     */
    static function string($value, &$error, Rule $rule)
    {
        if (isset($value) && is_scalar($value)){
            return strval($value);
        }
        $error = new Error('Должно быть строкой', 'string');
        return '';
    }

    /**
     * Проверка и фильтр скалярного значения
     * Нескалярные значения превращаются в 0
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return string
     */
    static function scalar($value, &$error, Rule $rule)
    {
        if (isset($value) && is_scalar($value)){
            return $value;
        }
        $error = new Error('Должно быть строкой, числом или логическим значением', 'scalar');
        return 0;
    }

    /**
     * Проверка и фильтр NULL
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return null
     */
    static function null($value, &$error, Rule $rule)
    {
        if (isset($value)/* && !(is_string($value) && in_array(strtolower($value), array('null')))*/){
            $error = new Error('Должно быть неопределённым (null)', 'null');
        }
        return null;
    }

    /**
     * Проверка и фильтр массива с учетом правил на его элементы
     * @param mixed $value Значение для проверки и фильтра
     * @param null|Error &$error Возвращаемый объект исключения, если элементы не соответсвуют правилам
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return array
     */
    static function arrays($value, &$error, Rule $rule)
    {
        $result = array();
        if (is_array($value)){
            $err_msg = 'Некорректное структура массива или значения в нём';
            // Контейнер для ошибок на элементы
            $error = null;
            //$error = new Error('Неверная структура.', 'arrays');
            // Сведения о правиле
            $rule_sub = array();
            $rule_default = null;
            $tree = false;
            foreach ($rule->arrays as $arg){
                if (is_array($arg)){
                    $rule_sub = $arg;
                }else
                if ($arg instanceof Rule){
                    $rule_default = $arg;
                }else
                if (is_string($arg)){
                    $rule_default = Rule::$arg();
                }else
                if ($arg === true){
                    $tree = true;
                }
            }
            // Перебор и проверка с фильтром всех элементов
            foreach ((array)$value as $key => $v){
                $sub_error = null;
                if (isset($rule_sub[$key])){
                    // Отсутствие элемента
                    if (isset($rule_sub[$key]->forbidden)){
                        $sub_error = new Error(array('Элемент "%s" должен отсутствовать', $key), 'forbidden');
                    }else{
                        $result[$key] = self::filter($v, $rule_sub[$key], $sub_error);
                    }
                    unset($rule_sub[$key]);
                }else{
                    if ($rule_default){
                        $result[$key] = self::filter($v, $rule_default, $sub_error);
                    }
                    // Если нет правила по умолчанию или оно не подошло и значение является массивом
                    if (!$rule_default || $sub_error){
                        // Если рекурсивная проверка вложенных массивов
                        if ($tree && (is_array($v) || $v instanceof Values)){
                            $sub_error = null;
                            $result[$key] = self::filter($v, $rule, $sub_error);
                        }
                    }
                    // Если на элемент нет правила, то его не будет в результате
                }
                if ($sub_error){
                    if (!$error) $error = new Error($err_msg, 'arrays');
                    $error->{$key}->add($sub_error);
                }
            }
            // Перебор оставшихся правил, для которых не оказалось значений
            foreach ($rule_sub as $key => $rule){
                if (isset($rule->required) && !isset($rule->forbidden)){
                    $result[$key] = self::filter(null, $rule, $sub_error);
                    if ($sub_error){
                        if (!$error) $error = new Error($err_msg, 'arrays');
                        $error->{$key}->required = "Обязательный элемент без значения по умолчанию";
                    }
                }

            }
            // Если ошибок у элементов нет, то удаляем объект исключения
            if (!isset($error) || !$error->isExist()){
                $error = null;
            }
        }else{
            $error = new Error('Должен быть массивом', 'arrays');
        }
        return $result;
    }

    /**
     * Проверка значения на соответствие объекту опредленного класса
     * @param mixed $value Значение для проверки
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return object|null
     */
    static function object($value, &$error, Rule $rule)
    {
        $class = isset($rule->object[0])? $rule->object[0] : null;
        if (is_object($value) && (empty($class) || $value instanceof $class)){
            return $value;
        }
        $error = new Error(array('Должен быть объектом класса "%s"', $class), 'object');
        return null;
    }

    /**
     * Проверка значения на соответствие объекту класса \boolive\values\Values
     * @param mixed $value Значение для проверки
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return \boolive\values\Values Любое значение превразается в объект \boolive\values\Values
     */
    static function values($value, &$error, Rule $rule)
    {
        if ($value instanceof Values){
            return $value;
        }
        $error = new Error('Должен быть объектом "\boolive\values\Values"', 'values');
        return new Values($value);
    }

    /**
     * Проверка значения на соответствие объекту класса \boolive\data\Entity
     * Если значение строка, то значение будет воспринято как uri объекта данных, и будет попытка выбора объекта из бд.
     * @param mixed $value Значение для проверки
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует типу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return \boolive\data\Entity|null
     */
    static function entity($value, &$error, Rule $rule)
    {
        $cond = isset($rule->entity[0])? $rule->entity[0] : false;
        if (!is_object($value)){
            if (is_string($value) && substr($value, 0,1) == '{'){
                $value = json_decode($value, true);
            }
            if (is_string($value)){
                // Пробуем получить объект по uri
                $value = Data2::read($value.'&cache=2');
            }else
            if (is_array($value)){
                if (isset($value['id'])){
                    $value = Data2::read($value['id'].'&cache=2');
                }else
                if (isset($value['uri'])){
                    $value = Data2::read($value['uri'].'&cache=2');
                }else
                if (isset($value['proto'])){
                    $value = Data2::read($value['proto'])->birth();
                    if (isset($value['parent']) && $parent = Data2::read($value['parent'])){
                        $parent->__set(null, $value);
                    }
                }
                unset($value['uri'], $value['proto'], $value['value']);
            }
        }
        if (!$value instanceof Entity){
            $error = new Error('Не является объектом данных "\boolive\data\Entity"', 'entity');
        }else
        if (!empty($cond) && !$value->verify($cond)){
            $error = new Error('Объект не соответсвует заданному условию', 'entity');
        }else
//        if (!$value->isExist()){
//            $error = new Error('Объект не существует', 'entity');
//        }else
        if (!$value->isAccessible()){
            $error = new Error('Объект недоступен для чтения', 'entity');
        }else{
            return $value;
        }
        return null;
    }

    /**
     * Проверка и фильтр значения правилами на выбор.
     * Если нет ни одного правила, то значение не проверяет и не фильтруется.
     * Если ниодно правило не подходит, то возвращается ошибка и значение от последнего правила.
     * @param mixed $value Значение для проверки
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed|null
     */
    static function any($value, &$error, Rule $rule)
    {
        $rules = $rule->any;
        if (empty($rules)) return $value;
        if (sizeof($rules) == 1 && is_array(reset($rules))) $rules = reset($rules);
        $result = null;
        foreach ($rules as $rule){
            $error = null;
            $result = self::filter($value, $rule, $error);
            if (!$error) return $result;
        }
        return $result;
    }

    /**
     * Максимально допустимое значение, длина или количество элементов. Правая граница отрезка
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function max($value, &$error, Rule $rule)
    {
        $max = isset($rule->max[0])? $rule->max[0] : null;
        if (is_int($value) || is_double($value)){
            $result = min($max, $value);
            if ($value != $result) $error = new Error(array('Требуется не больше %s', $max), 'max');
        }else
        if (is_string($value) && mb_strlen($value) > $max){
            $result = mb_substr($value, 0, $max);
            if ($value != $result) $error = new Error(array('Требуется не больше %s символов(а)', $max), 'max');
        }else
        if (is_array($value) && sizeof($value) > $max){
            $result = array_slice($value, 0, $max);
            if ($value != $result) $error = new Error(array('Требуется не больше %s элементов(а)', $max), 'max');
        }else{
            $result = $value;
        }
        return $result;
    }

    /**
     * Минимально допустимое значение, длина или количество элементов. Левая граница отрезка
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function min($value, &$error, Rule $rule)
    {
        $min = isset($rule->min[0])? $rule->min[0] : null;
        $result = $value;
        if (is_int($value) || is_double($value)){
            $result = max($min, $value);
            if ($value != $result) $error = new Error(array('Требуется не меньше %s', $min), 'min');
        }else
        if (is_string($value) && mb_strlen($value) < $min){
            $error = new Error(array('Требуется не меньше %s символов(а)', $min), 'min');
        }else
        if (is_array($value) && sizeof($value) < $min){
            $error = new Error(array('Длжно быть не меньше %s элементов(а)', $min), 'min');
        }
        return $result;
    }

    /**
     * Меньше указанного значения, длины или количества элементов. Правая граница интервала
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function less($value, &$error, Rule $rule)
    {
        $less = isset($rule->less[0])? $rule->less[0] : null;
        if ((is_int($value) || is_double($value)) && !($value < $less)){
            $result = $less - 1;
            if ($value != $result) $error = new Error(array('Требуется меньше %s', $less), 'less');
        }else
        if (is_string($value) && !(mb_strlen($value) < $less)){
            $result = mb_substr($value, 0, $less - 1);
            if ($value != $result) $error = new Error(array('Требуется меньше %s символов(а)', $less), 'less');
        }else
        if (is_array($value) && !(sizeof($value) < $less)){
            $result = array_slice($value, 0, $less - 1);
            if ($value != $result) $error = new Error(array('Требуется меньше %s элементов(а)', $less), 'less');
        }else{
            $result = $value;
        }
        return $result;
    }

    /**
     * Больше указанного значения, длины или количества элементов. Левая граница интервала
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function more($value, &$error, Rule $rule)
    {
        $more = isset($rule->more[0])? $rule->more[0] : null;
        if ((is_int($value) || is_double($value)) && !($value > $more)){
            $value = $more + 1;
            $error = new Error(array('Требуется больше %s', $more), 'more');
        }else
        if (is_string($value) && !(mb_strlen($value) > $more)){
            if ($more==0){
                $error = new Error('Требуется заполнить', 'more');
            }else{
                $error = new Error(array('Требуется больше %s символов(а)', $more), 'more');
            }
        }else
        if (is_array($value) && !(sizeof($value) > $more)){
            $error = new Error(array('Требуется больше %s элементов(а)', $more), 'more');
        }
        return $value;
    }

    /**
     * Проверка на равенство указанному значению
     * @param mixed $value Проверяемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function eq($value, &$error, Rule $rule)
    {
        $eq = isset($rule->eq[0])? $rule->eq[0] : null;
        $strong = isset($rule->eq[1])? $rule->eq[1] : false;
        if (!(($strong && $value===$eq) || (!$strong && $value==$eq))){
            $error = new Error(array('Должно равняться %s', array($eq)), 'eq');
            $value = $eq;
        }
        return $value;
    }

    /**
     * Проверка на неравенство указанному значению
     * @param mixed $value Проверяемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function not($value, &$error, Rule $rule)
    {
        $not = isset($rule->not[0])? $rule->not[0] : null;
        if ($value==$not){
            $value = null;
            $error = new Error(array('Не должно равняться %s', array($not)), 'not');
        }
        return $value;
    }

    /**
     * Допустимые значения. Через запятую или массив
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function in($value, &$error, Rule $rule)
    {
        $list = $rule->in;
        if (sizeof($list) == 1 && is_array($list[0])) $list = $list[0];
        if (!in_array($value, $list)){
            $value = null;
            $error = new Error('Значение не в списке допустимых', 'in');
        }
        return $value;
    }

    /**
     * Недопустимые значения. Через запятую или массив
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function not_in($value, &$error, Rule $rule)
    {
        $list = $rule->not_in;
        if (sizeof($list) == 1 && is_array($list[0])) $list = $list[0];
        if (in_array($value, $list)){
            $value = null;
            $error = new Error('Значение в списке запрещенных', 'not_in');
        }
        return $value;
    }

    /**
     * Обрезание строки
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function trim($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            $result = trim($value);
            if ($result != $value) $error = new Error('Имеются пробельные символы в начале или конце строки', 'trim');
            return $result;
        }
        return $value;
    }

    /**
     * Экранирование html символов
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function escape($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            if (empty($rule->escape[1])){
                $result = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }else{
                $result = htmlentities($value, ENT_QUOTES, 'UTF-8');
            }
            if ($result != $value) $error = new Error('Содержит запрещенные специальные символы HTML ', 'escape');
            return $result;
        }
        return $value;
    }

    /**
     * Вырезание html тегов
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function strip_tags($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            $tags = $rule->strip_tags[1];
            $result = strip_tags($value, $tags);
            if ($result != $value) $error = new Error('Содержит запрещенные HTML теги', 'strip_tags');
            return $result;
        }
        return $value;
    }

    /**
     * Email адрес
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function email($value, &$error, Rule $rule)
    {
        if (!is_string($value) || ($value!=='' && !filter_var($value, FILTER_VALIDATE_EMAIL))){
            $error = new Error('Некорректный email адрес', 'email');
        }
        return $value;
    }

    /**
     * URL
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function url($value, &$error, Rule $rule)
    {
        if (!is_string($value) || ($value!=='' && !filter_var($value, FILTER_VALIDATE_URL))){
            $error = new Error('Некорректный URL', 'url');
        }
        return $value;
    }

    /**
     * URI = URL + URN
     * Может быть целым числом
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function uri($value, &$error, Rule $rule)
    {
        if (is_string($value) || is_int($value)){
            if (empty($value) || trim($value, '/')===''){
                return $value;
            }
            $check = $value;
            if (!preg_match('#^([^:/]+://).*$#iu', $check)) $check = 'http://check/'.trim($check, '/');
            if (filter_var($check, FILTER_VALIDATE_URL)){
                return $value;
            }
        }
        $error = new Error(array('"Некорректный URI', $value), 'uri');
        return $value;
    }

    /**
     * IP
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function ip($value, &$error, Rule $rule)
    {
        if (!is_string($value) || ($value!=='' && !filter_var($value, FILTER_VALIDATE_IP))){
            $error = new Error('Некорректный IP адрес', 'ip');
        }
        return $value;
    }

    /**
     * Проверка на совпадение одному из регулярных выражений
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function regexp($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            $patterns = $rule->regexp;
            if (sizeof($patterns) == 1 && is_array($patterns[0])) $patterns = $patterns[0];
            foreach ($patterns as $pattern){
                if (empty($pattern)) return $value;
                if (preg_match($pattern, $value)) return $value;
            }
        }
        $error = new Error('Некорректное регулярное выражение', 'regexp');
        return $value;
    }

    /**
     * Проверка на совпадения одному из паттернов в стиле оболочки операционной системы: "*gr[ae]y"
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function ospatterns($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            $patterns = $rule->ospatterns;
            if (sizeof($patterns) == 1 && is_array($patterns[0])) $patterns = $patterns[0];
            foreach ($patterns as $pattern){
                if (fnmatch($pattern, $value)) return $value;
            }
        }
        $error = new Error('Некорректный шаблон строки', 'ospatterns');
        return $value;
    }

    /**
     * HEX формат числа из 6 или 3 символов. Код цвета #FFFFFF. Возможны сокращения и опущение #
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return mixed
     */
    static function color($value, &$error, Rule $rule)
    {
        if (is_scalar($value)){
            $value = trim($value, ' #');
            if (preg_replace('/^[0-9ABCDEF]{0,6}$/ui', '', $value) == '' && (strlen($value) == 6 || strlen($value) == 3)){
                return '#'.$value;
            }
        }
        $error = new Error('Некорректный код цвета', 'color');
        return '#000000';
    }

    /**
     * Строка в нижнем регистре
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return string
     */
    static function lowercase($value, &$error, Rule $rule)
    {
        $result = mb_strtolower($value, 'utf-8');
        if ($value != $result){
            $error = new Error('Не все символы в нижнем регистре', 'lowercase');
        }
        return $result;
    }

    /**
     * Строка в верхнем регистре
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return string
     */
    static function uppercase($value, &$error, Rule $rule)
    {
        $result = mb_strtoupper($value, 'utf-8');
        if ($value != $result){
            $error = new Error('Не все символы в верхнем регистре', 'uppercase');
        }
        return $result;
    }

    /**
     * Условие поиска или валидации объекта
     * @param mixed $value Фильтруемое значение
     * @param null|Error &$error Возвращаемый объект исключения, если значение не соответсвует правилу
     * @param \boolive\values\Rule $rule Объект правила. Аргументы одноименного фильтра применяются в методе
     * @return string
     */
    static function condition($value, &$error, Rule $rule)
    {
        $result = \boolive\data\Data2::normalizeCond($value);
        if ($result === null){
            $error = new Error('Не является условием поиска', 'condition');
        }
        return $result;
    }
}