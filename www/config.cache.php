<?php
/**
 * Конфигурация кэш=хранилищ
 * Хранилище определяется совпадением ключа хранилища с левой частью ключа кэш-значения
 * Указывается класс хранидища и параметры подключения. Ключ хранинилища - это ключ массива конфигурации
 * @var array
 */
$stores = array(
    // Модуль кэширования по умолчанию для всех
    '' => array(
        'class' => '\boolive\cache\stores\FileCache',
        'connect' => array(
            'dir' => DIR_SERVER_TEMP.'cache/'
        )
    )
);