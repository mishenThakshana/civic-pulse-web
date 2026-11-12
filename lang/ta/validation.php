<?php

/*
| Validation messages for the citizen API in Tamil. Rules not listed here fall
| back to the English messages.
*/

return [
    'required' => ':attribute அவசியம்.',
    'email' => ':attribute சரியான மின்னஞ்சல் முகவரியாக இருக்க வேண்டும்.',
    'unique' => 'இந்த :attribute ஏற்கனவே பயன்பாட்டில் உள்ளது.',
    'max' => [
        'string' => ':attribute :max எழுத்துகளுக்கு மேல் இருக்கக்கூடாது.',
        'file' => ':attribute :max கிலோபைட்டுகளுக்கு மேல் இருக்கக்கூடாது.',
        'numeric' => ':attribute :max ஐ விட அதிகமாக இருக்கக்கூடாது.',
    ],
    'min' => [
        'string' => ':attribute குறைந்தது :min எழுத்துகள் கொண்டிருக்க வேண்டும்.',
    ],
    'mimes' => ':attribute :values வகைக் கோப்பாக இருக்க வேண்டும்.',
    'confirmed' => ':attribute உறுதிப்படுத்தல் பொருந்தவில்லை.',
    'numeric' => ':attribute எண்ணாக இருக்க வேண்டும்.',
    'between' => [
        'numeric' => ':attribute :min மற்றும் :max இடையே இருக்க வேண்டும்.',
    ],
    'file' => ':attribute ஒரு கோப்பாக இருக்க வேண்டும்.',
    'exists' => 'தேர்ந்தெடுக்கப்பட்ட :attribute செல்லாது.',
    'string' => ':attribute உரையாக இருக்க வேண்டும்.',
    'integer' => ':attribute முழு எண்ணாக இருக்க வேண்டும்.',
    'in' => 'தேர்ந்தெடுக்கப்பட்ட :attribute செல்லாது.',
    'attributes' => [
        'name' => 'பெயர்',
        'email' => 'மின்னஞ்சல் முகவரி',
        'phone' => 'தொலைபேசி எண்',
        'nic_number' => 'தேசிய அடையாள அட்டை எண்',
        'nic_photo_front' => 'அடையாள அட்டையின் முன்பக்கப் புகைப்படம்',
        'nic_photo_back' => 'அடையாள அட்டையின் பின்பக்கப் புகைப்படம்',
        'password' => 'கடவுச்சொல்',
        'description' => 'விளக்கம்',
        'latitude' => 'அகலாங்கு',
        'longitude' => 'நெட்டாங்கு',
        'address' => 'முகவரி',
        'contact_phone' => 'தொடர்பு தொலைபேசி எண்',
        'photo' => 'புகைப்படம்',
        'category_id' => 'வகை',
        'device_name' => 'சாதனப் பெயர்',
        'token' => 'டோக்கன்',
        'platform' => 'தளம்',
    ],
];
