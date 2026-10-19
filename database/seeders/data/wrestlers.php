<?php

/*
| Full roster (§9) with weight classes (§12) and specialties (§21).
| Columns: name, roster_type, placeholder_brand, weight_class, billed_weight_lb.
| Brand assignments are placeholders; rosters are built by the draft.
*/

$raw = [
    ['Big Show', 'super_heavyweight', 485],
    ['Lance Cade', 'heavyweight', 267],
    ['Trevor Murdoch', 'heavyweight', 241],
    ['Carlito', 'light_heavyweight', 240],
    ['Chavo Guerrero', 'cruiserweight', 215],
    ['Chris Jericho', 'light_heavyweight', 225],
    ['Chris Masters', 'heavyweight', 265],
    ['Danny Basham', 'heavyweight', 250],
    ['Edge', 'heavyweight', 240],
    ['Eugene', 'heavyweight', 235],
    ['The Hurricane', 'cruiserweight', 191],
    ['John Cena', 'heavyweight', 251],
    ['Kane', 'super_heavyweight', 324],
    ['Kurt Angle', 'heavyweight', 240],
    ['Rene Dupree', 'heavyweight', 260],
    ['Ric Flair', 'heavyweight', 243],
    ['Rob Conway', 'heavyweight', 230],
    ['Rob Van Dam', 'light_heavyweight', 237],
    ['Shawn Michaels', 'light_heavyweight', 225],
    ['Shelton Benjamin', 'heavyweight', 248],
    ['Snitsky', 'heavyweight', 285],
    ['Tajiri', 'cruiserweight', 180],
    ['Triple H', 'heavyweight', 255],
    ['Jeff Hardy', 'light_heavyweight', 225],
    ['Val Venis', 'light_heavyweight', null],
    ['Mick Foley', 'heavyweight', 300],
    ['Viscera', 'super_heavyweight', null],
    ['Umaga', 'super_heavyweight', null],
    ['JTG', 'cruiserweight', null],
    ['Shad', 'heavyweight', null],
    ['Robbie McAllister', 'heavyweight', null],
    ['Rory McAllister', 'heavyweight', null],
    ['Bubba Ray Dudley', 'heavyweight', null],
    ['D-Von Dudley', 'heavyweight', null],
];

$smackdown = [
    ['Batista', 'heavyweight', 290],
    ['Mr. Kennedy', 'light_heavyweight', 235],
    ['Joey Mercury', 'cruiserweight', 191],
    ['Johnny Nitro', 'light_heavyweight', 224],
    ['Matt Hardy', 'light_heavyweight', 235],
    ['Booker T', 'heavyweight', 253],
    ['Charlie Haas', 'heavyweight', 249],
    ['Chris Benoit', 'heavyweight', 220],
    ['Christian', 'light_heavyweight', 227],
    ['Daivari', 'cruiserweight', 189],
    ['Doug Basham', 'heavyweight', 245],
    ['Eddie Guerrero', 'light_heavyweight', 221],
    ['Heidenreich', 'super_heavyweight', 300],
    ['JBL', 'heavyweight', 280],
    ['Mark Jindrak', 'heavyweight', 265],
    ['Muhammad Hassan', 'heavyweight', 245],
    ['Orlando Jordan', 'heavyweight', 257],
    ['Paul London', 'cruiserweight', 180],
    ['Randy Orton', 'heavyweight', 245],
    ['Rey Mysterio', 'cruiserweight', 175],
    ['Scotty 2 Hotty', 'light_heavyweight', 209],
    ['Spike Dudley', 'cruiserweight', 150],
    ['Stevie Richards', 'light_heavyweight', 220],
    ['Sylvain Grenier', 'heavyweight', 250],
    ['Undertaker', 'super_heavyweight', 309],
    ['William Regal', 'heavyweight', 240],
    ['Bobby Lashley', 'heavyweight', 273],
    ['The Great Khali', 'ultra_heavyweight', 420],
    ['Brian Kendrick', 'cruiserweight', null],
    ['Deuce', 'light_heavyweight', null],
    ['Domino', 'light_heavyweight', null],
    ['Mark Henry', 'super_heavyweight', null],
    ['The Boogeyman', 'heavyweight', null],
    ['Super Crazy', 'cruiserweight', null],
    ['Nunzio', 'cruiserweight', null],
    ['Psicosis', 'cruiserweight', null],
];

$rawDivas = ['Trish Stratus', 'Lita', 'Ashley Massaro', 'Mickie James', 'Maria', 'Victoria'];
$smackdownDivas = ['Christy Hemme', 'Joy Giovanni', 'Michelle McCool', 'Stacy Keibler', 'Melina', 'Torrie Wilson', 'Cherry'];

$legends = [
    ['Andre The Giant', 'ultra_heavyweight', 520],
    ['Bret Hart', 'heavyweight', 234],
    ['British Bulldog', 'heavyweight', 253],
    ['Hulk Hogan', 'super_heavyweight', 303],
    ['Jimmy Hart', 'cruiserweight', 166],
    ['Junkyard Dog', 'heavyweight', 280],
    ['Stone Cold Steve Austin', 'heavyweight', 252],
    ['Ted DiBiase', 'heavyweight', 260],
    ['The Rock', 'heavyweight', 275],
    ['Jake Roberts', 'heavyweight', 253],
    ['Dusty Rhodes', 'heavyweight', null],
    ['Jerry Lawler', 'heavyweight', null],
    ['Jim Neidhart', 'heavyweight', null],
    ['Mr. Perfect', 'heavyweight', null],
    ['Roddy Piper', 'heavyweight', null],
    ['Shane McMahon', 'heavyweight', null],
    ['Tazz', 'heavyweight', null],
    ['Rick Rude', 'heavyweight', null],
    ['Sabu', 'light_heavyweight', null],
    ['Sgt. Slaughter', 'super_heavyweight', null],
    ['Terry Funk', 'heavyweight', null],
    ['Tommy Dreamer', 'heavyweight', null],
    ['Bob Orton', 'heavyweight', null],
    ['Hacksaw Jim Duggan', 'heavyweight', null],
    ['Tatanka', 'heavyweight', null],
    ['Animal', 'heavyweight', null],
    ['Hawk', 'heavyweight', null],
];

$rows = [];

foreach ($raw as [$name, $class, $weight]) {
    $rows[] = ['name' => $name, 'roster_type' => 'superstar', 'placeholder_brand' => 'raw', 'weight_class' => $class, 'billed_weight_lb' => $weight];
}

foreach ($smackdown as [$name, $class, $weight]) {
    $rows[] = ['name' => $name, 'roster_type' => 'superstar', 'placeholder_brand' => 'smackdown', 'weight_class' => $class, 'billed_weight_lb' => $weight];
}

foreach ($rawDivas as $name) {
    $rows[] = ['name' => $name, 'roster_type' => 'diva', 'placeholder_brand' => 'raw', 'weight_class' => 'featherweight', 'billed_weight_lb' => null];
}

foreach ($smackdownDivas as $name) {
    $rows[] = ['name' => $name, 'roster_type' => 'diva', 'placeholder_brand' => 'smackdown', 'weight_class' => 'featherweight', 'billed_weight_lb' => null];
}

foreach ($legends as [$name, $class, $weight]) {
    $rows[] = ['name' => $name, 'roster_type' => 'legend', 'placeholder_brand' => null, 'weight_class' => $class, 'billed_weight_lb' => $weight];
}

return [
    'wrestlers' => $rows,
    'specialties' => [
        'The Great Khali' => ['steel_cage'],
        'Edge' => ['tlc', 'ladder'],
        'Christian' => ['tlc', 'ladder'],
        'Mick Foley' => ['hardcore'],
        'Matt Hardy' => ['tlc', 'ladder'],
        'Jeff Hardy' => ['tlc', 'ladder'],
        'Chris Masters' => ['submission'],
        'Rob Van Dam' => ['hardcore'],
        'Shawn Michaels' => ['ladder', 'iron_man'],
        'Shelton Benjamin' => ['ladder'],
        'Triple H' => ['hell_in_a_cell'],
        'Joey Mercury' => ['ladder'],
        'Johnny Nitro' => ['ladder'],
        'Chris Benoit' => ['submission'],
        'JBL' => ['first_blood'],
        'Eddie Guerrero' => ['first_blood'],
        'Spike Dudley' => ['table'],
        'Bubba Ray Dudley' => ['table'],
        'D-Von Dudley' => ['table'],
        'Undertaker' => ['hell_in_a_cell'],
        'Bret Hart' => ['submission', 'iron_man'],
        'John Cena' => ['submission'],
        'Kurt Angle' => ['submission'],
        'Stevie Richards' => ['hardcore'],
        'Sabu' => ['hardcore'],
        'Terry Funk' => ['hardcore'],
        'Tommy Dreamer' => ['hardcore'],
    ],
];
