<?php

/*
| Validation messages for the citizen API in Sinhala. Rules not listed here fall
| back to the English messages.
*/

return [
    'required' => ':attribute අවශ්‍යයි.',
    'email' => ':attribute වලංගු ඊමේල් ලිපිනයක් විය යුතුය.',
    'unique' => 'මෙම :attribute දැනටමත් භාවිතයේ ඇත.',
    'max' => [
        'string' => ':attribute අක්ෂර :max කට වඩා වැඩි නොවිය යුතුය.',
        'file' => ':attribute කිලෝබයිට් :max කට වඩා විශාල නොවිය යුතුය.',
        'numeric' => ':attribute :max ට වඩා වැඩි නොවිය යුතුය.',
    ],
    'min' => [
        'string' => ':attribute අවම වශයෙන් අක්ෂර :min ක් විය යුතුය.',
    ],
    'mimes' => ':attribute :values වර්ගයේ ගොනුවක් විය යුතුය.',
    'confirmed' => ':attribute තහවුරු කිරීම නොගැළපේ.',
    'numeric' => ':attribute අංකයක් විය යුතුය.',
    'between' => [
        'numeric' => ':attribute :min සහ :max අතර විය යුතුය.',
    ],
    'file' => ':attribute ගොනුවක් විය යුතුය.',
    'exists' => 'තෝරාගත් :attribute වලංගු නැත.',
    'string' => ':attribute පෙළක් විය යුතුය.',
    'integer' => ':attribute පූර්ණ සංඛ්‍යාවක් විය යුතුය.',
    'in' => 'තෝරාගත් :attribute වලංගු නැත.',
    'attributes' => [
        'name' => 'නම',
        'email' => 'ඊමේල් ලිපිනය',
        'phone' => 'දුරකථන අංකය',
        'nic_number' => 'ජාතික හැඳුනුම්පත් අංකය',
        'nic_photo_front' => 'හැඳුනුම්පතේ ඉදිරිපස ඡායාරූපය',
        'nic_photo_back' => 'හැඳුනුම්පතේ පසුපස ඡායාරූපය',
        'password' => 'මුරපදය',
        'description' => 'විස්තරය',
        'latitude' => 'අක්ෂාංශය',
        'longitude' => 'දේශාංශය',
        'address' => 'ලිපිනය',
        'contact_phone' => 'සම්බන්ධතා දුරකථන අංකය',
        'photo' => 'ඡායාරූපය',
        'category_id' => 'කාණ්ඩය',
        'device_name' => 'උපාංගයේ නම',
        'token' => 'ටෝකනය',
        'platform' => 'වේදිකාව',
    ],
];
