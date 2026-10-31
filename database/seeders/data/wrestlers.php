<?php

/*
| Full roster (§9) with weight classes (§12) and specialties (§21).
| Columns: name, roster_type, placeholder_brand, weight_class, billed_weight_lb.
| Brands are the WWE rosters of Nov 14, 2005 (SvR 2006's release), trimmed
| to 30 each (docs/20261024-2005-roster.md). A "Use WWE roster" game signs
| them as listed; a manual draft ignores them. Free agents have no brand.
*/

$raw = [
    ['Big Show', 'super_heavyweight', 485],
    ['Lance Cade', 'heavyweight', 267],
    ['Trevor Murdoch', 'heavyweight', 241],
    ['Carlito', 'light_heavyweight', 240],
    ['Chris Masters', 'heavyweight', 265],
    ['Edge', 'heavyweight', 240],
    ['Eugene', 'light_heavyweight', 235],
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
    ['Val Venis', 'light_heavyweight', null],
    ['Mick Foley', 'heavyweight', 300],
    ['Viscera', 'super_heavyweight', null],
    ['Hacksaw Jim Duggan', 'heavyweight', null],
    ['Tatanka', 'heavyweight', null],
];

$smackdown = [
    ['Batista', 'heavyweight', 290],
    ['Mr. Kennedy', 'light_heavyweight', 235],
    ['Joey Mercury', 'light_heavyweight', 191],
    ['Johnny Nitro', 'light_heavyweight', 224],
    ['Booker T', 'heavyweight', 253],
    ['Chris Benoit', 'light_heavyweight', 220],
    ['Daivari', 'cruiserweight', 189],
    ['Eddie Guerrero', 'light_heavyweight', 221],
    ['Heidenreich', 'super_heavyweight', 300],
    ['JBL', 'heavyweight', 280],
    ['Orlando Jordan', 'heavyweight', 257],
    ['Paul London', 'cruiserweight', 180],
    ['Randy Orton', 'heavyweight', 245],
    ['Rey Mysterio', 'cruiserweight', 175],
    ['Scotty 2 Hotty', 'light_heavyweight', 209],
    ['Stevie Richards', 'light_heavyweight', 220],
    ['Sylvain Grenier', 'heavyweight', 250],
    ['Undertaker', 'super_heavyweight', 309],
    ['William Regal', 'heavyweight', 240],
    ['Bobby Lashley', 'heavyweight', 273],
    ['Brian Kendrick', 'cruiserweight', null],
    ['Super Crazy', 'cruiserweight', null],
    ['Nunzio', 'cruiserweight', null],
    ['Psicosis', 'cruiserweight', null],
    ['Funaki', 'cruiserweight', 195],
    ['Hardcore Holly', 'heavyweight', 235],
    ['Vito', 'heavyweight', 255],
    ['Road Warrior Animal', 'heavyweight', null],
];

$freeAgents = [
    ['Chavo Guerrero', 'cruiserweight', 215],
    ['Chris Jericho', 'light_heavyweight', 225],
    ['Danny Basham', 'heavyweight', 250],
    ['Jeff Hardy', 'light_heavyweight', 225],
    ['Umaga', 'super_heavyweight', null],
    ['JTG', 'cruiserweight', null],
    ['Shad', 'heavyweight', null],
    ['Robbie McAllister', 'heavyweight', null],
    ['Rory McAllister', 'heavyweight', null],
    ['Bubba Ray Dudley', 'heavyweight', null],
    ['D-Von Dudley', 'heavyweight', null],
    ['Dolph Ziggler', 'light_heavyweight', 223],
    ['Kenzo Suzuki', 'heavyweight', 260],
    ['Lance Storm', 'light_heavyweight', 231],
    ['Marty Jannetty', 'light_heavyweight', 234],
    ['Maven', 'light_heavyweight', 225],
    ['Rhino', 'heavyweight', 295],
    ['Rosey', 'super_heavyweight', 375],
    ['Tyson Tomko', 'heavyweight', 292],
    ['Matt Hardy', 'light_heavyweight', 235],
    ['Charlie Haas', 'heavyweight', 249],
    ['Christian', 'light_heavyweight', 227],
    ['Doug Basham', 'heavyweight', 245],
    ['Mark Jindrak', 'heavyweight', 265],
    ['Muhammad Hassan', 'heavyweight', 245],
    ['Spike Dudley', 'cruiserweight', 150],
    ['The Great Khali', 'ultra_heavyweight', 420],
    ['Deuce', 'light_heavyweight', null],
    ['Domino', 'light_heavyweight', null],
    ['Mark Henry', 'super_heavyweight', null],
    ['The Boogeyman', 'heavyweight', null],
    ['Billy Kidman', 'cruiserweight', 195],
    ['The Blue Meanie', 'super_heavyweight', 323],
    ['Frankie Kazarian', 'cruiserweight', 215],
    ['Jamie Noble', 'cruiserweight', 198],
    ['Juventud', 'cruiserweight', 165],
    ['Kid Kash', 'cruiserweight', 202],
    ['Luther Reigns', 'heavyweight', 285],
    ['Matt Morgan', 'super_heavyweight', 308],
    ['Paul Burchill', 'heavyweight', 247],
    ['Shannon Moore', 'cruiserweight', 202],
    ['Simon Dean', 'cruiserweight', 220],
    ['Tommy Dreamer', 'heavyweight', null],
    ['Al Snow', 'light_heavyweight', 235],
    ['Goldust', 'light_heavyweight', 232],
    ['Jimmy Wang Yang', 'cruiserweight', 206],
    ['Chuck Palumbo', 'heavyweight', null],
    ['MVP', 'heavyweight', null],
];

$rawDivas = ['Trish Stratus', 'Lita', 'Mickie James', 'Victoria', 'Torrie Wilson'];
$smackdownDivas = ['Melina', 'Sharmell'];
$freeAgentDivas = [
    'Ashley Massaro', 'Maria', 'Candice Michelle', 'Hiroko', 'Ivory', 'Molly Holly',
    'Christy Hemme', 'Joy Giovanni', 'Michelle McCool', 'Stacy Keibler', 'Cherry',
    'Dawn Marie', 'Jillian Hall', 'Vickie Guerrero', 'Mae Young',
];

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
    ['Jim Neidhart', 'heavyweight', null],
    ['Mr. Perfect', 'heavyweight', null],
    ['Roddy Piper', 'heavyweight', null],
    ['Shane McMahon', 'heavyweight', null],
    ['Tazz', 'heavyweight', null],
    ['Rick Rude', 'heavyweight', null],
    ['Sabu', 'light_heavyweight', null],
    ['Sgt. Slaughter', 'super_heavyweight', null],
    ['Terry Funk', 'heavyweight', null],
    ['Bob Orton', 'heavyweight', null],
    ['Road Warrior Hawk', 'heavyweight', null],
    ['Arn Anderson', 'heavyweight', 255],
    ['The Brooklyn Brawler', 'heavyweight', 248],
    ['Dean Malenko', 'cruiserweight', 212],
    ['Fit Finlay', 'light_heavyweight', 233],
    ['Gerald Brisco', 'light_heavyweight', 209],
    ['Jerry Lawler', 'light_heavyweight', null],
    ['Jack Brisco', 'light_heavyweight', 234],
    ['Jimmy Snuka', 'light_heavyweight', 235],
    ['Michael Hayes', 'heavyweight', 255],
    ['Mr. McMahon', 'heavyweight', 248],
    ['Pat Patterson', 'light_heavyweight', 237],
    ['Ricky Steamboat', 'light_heavyweight', 235],
    ['Faarooq', 'heavyweight', 270],
    ['Brutus Beefcake', 'heavyweight', null],
    ['Bam Bam Bigelow', 'super_heavyweight', null],
];

$rows = [];

foreach ([['raw', $raw], ['smackdown', $smackdown], [null, $freeAgents]] as [$brand, $list]) {
    foreach ($list as [$name, $class, $weight]) {
        $rows[] = ['name' => $name, 'roster_type' => 'superstar', 'placeholder_brand' => $brand, 'weight_class' => $class, 'billed_weight_lb' => $weight];
    }
}

foreach ([['raw', $rawDivas], ['smackdown', $smackdownDivas], [null, $freeAgentDivas]] as [$brand, $list]) {
    foreach ($list as $name) {
        $rows[] = ['name' => $name, 'roster_type' => 'diva', 'placeholder_brand' => $brand, 'weight_class' => 'featherweight', 'billed_weight_lb' => null];
    }
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
        'Mick Foley' => ['hardcore', 'hell_in_a_cell'],
        'Kane' => ['first_blood'],
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
        'Spike Dudley' => ['table', 'hardcore'],
        'Bubba Ray Dudley' => ['table', 'hardcore'],
        'D-Von Dudley' => ['table', 'hardcore'],
        'Undertaker' => ['hell_in_a_cell'],
        'Bret Hart' => ['submission', 'iron_man'],
        'Stone Cold Steve Austin' => ['first_blood'],
        'John Cena' => ['submission'],
        'Kurt Angle' => ['submission'],
        'Stevie Richards' => ['hardcore'],
        'Sabu' => ['hardcore'],
        'Terry Funk' => ['hardcore'],
        'Tommy Dreamer' => ['hardcore'],
        'Hardcore Holly' => ['hardcore'],
        'Rhino' => ['hardcore'],
        'Bam Bam Bigelow' => ['hardcore'],
        'The Blue Meanie' => ['first_blood', 'hardcore'],
    ],
];
