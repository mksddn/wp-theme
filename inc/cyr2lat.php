<?php
/*
Plugin Name: Cyr2Lat
Description: A simple plugin to convert Cyrillic to Latin characters in WordPress permalinks and ACF fields.
Version: 1.0
Author: mksddn
*/

// Prevent direct access to the file
if (! defined( 'ABSPATH' )) {
    exit;
}

// Main function to convert Cyrillic characters to Latin
function my_cyr_to_lat( $text ): string|array {
    $cyrillic = [
        'А',
        'Б',
        'В',
        'Г',
        'Д',
        'Е',
        'Ё',
        'Ж',
        'З',
        'И',
        'Й',
        'К',
        'Л',
        'М',
        'Н',
        'О',
        'П',
        'Р',
        'С',
        'Т',
        'У',
        'Ф',
        'Х',
        'Ц',
        'Ч',
        'Ш',
        'Щ',
        'Ъ',
        'Ы',
        'Ь',
        'Э',
        'Ю',
        'Я',
        'а',
        'б',
        'в',
        'г',
        'д',
        'е',
        'ё',
        'ж',
        'з',
        'и',
        'й',
        'к',
        'л',
        'м',
        'н',
        'о',
        'п',
        'р',
        'с',
        'т',
        'у',
        'ф',
        'х',
        'ц',
        'ч',
        'ш',
        'щ',
        'ъ',
        'ы',
        'ь',
        'э',
        'ю',
        'я',
    ];

    $latin = [
        'A',
        'B',
        'V',
        'G',
        'D',
        'E',
        'E',
        'Zh',
        'Z',
        'I',
        'Y',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'R',
        'S',
        'T',
        'U',
        'F',
        'H',
        'Ts',
        'Ch',
        'Sh',
        'Sch',
        '',
        'Y',
        '',
        'E',
        'Yu',
        'Ya',
        'a',
        'b',
        'v',
        'g',
        'd',
        'e',
        'e',
        'zh',
        'z',
        'i',
        'y',
        'k',
        'l',
        'm',
        'n',
        'o',
        'p',
        'r',
        's',
        't',
        'u',
        'f',
        'h',
        'ts',
        'ch',
        'sh',
        'sch',
        '',
        'y',
        '',
        'e',
        'yu',
        'ya',
    ];

    return str_replace( $cyrillic, $latin, $text );
}


// Process only ACF field names during load
function my_cyr_to_lat_acf_field_names( array $field ): array {
    if (isset( $field['name'] )) {
        $field['name'] = my_cyr_to_lat( $field['name'] );
    }

    return $field;
}


// Hook into WordPress filters and ACF hooks
function my_cyr_to_lat_hooks(): void {
    add_filter( 'sanitize_title', 'my_cyr_to_lat', 9 );
    add_filter( 'sanitize_file_name', 'my_cyr_to_lat', 9 );
    add_filter( 'acf/load_field', 'my_cyr_to_lat_acf_field_names', 10, 1 );
}


add_action( 'init', 'my_cyr_to_lat_hooks' );
add_action( 'acf/init', 'my_cyr_to_lat_hooks' );
