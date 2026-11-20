<?php

/*
| Full roster (§9) with weight classes (§12) and specialties (§21).
| Columns: name, roster_type, placeholder_brand, weight_class, billed_weight_lb,
| monthly_salary.
| Brands are the WWE rosters of Nov 14, 2005 (SvR 2006's release), trimmed
| to 30 each (docs/20261024-2005-roster.md). A "Use WWE roster" game signs
| them; a manual draft ignores them. Free agents have no brand.
| Every list is sorted by name: keep it that way when adding someone.
*/

$raw = [
    ['Big Show', 'super_heavyweight', 485],
    ['Carlito', 'light_heavyweight', 240],
    ['Chris Masters', 'heavyweight', 265],
    ['Edge', 'heavyweight', 240],
    ['Eugene', 'light_heavyweight', 235],
    ['Hacksaw Jim Duggan', 'heavyweight', null],
    ['John Cena', 'heavyweight', 251],
    ['Kane', 'super_heavyweight', 324],
    ['Kurt Angle', 'heavyweight', 240],
    ['Lance Cade', 'heavyweight', 267],
    ['Mick Foley', 'heavyweight', 300],
    ['Rene Dupree', 'heavyweight', 260],
    ['Ric Flair', 'heavyweight', 243],
    ['Rob Conway', 'heavyweight', 230],
    ['Rob Van Dam', 'light_heavyweight', 237],
    ['Shawn Michaels', 'light_heavyweight', 225],
    ['Shelton Benjamin', 'heavyweight', 248],
    ['Snitsky', 'heavyweight', 285],
    ['Tajiri', 'cruiserweight', 180],
    ['Tatanka', 'heavyweight', null],
    ['The Hurricane', 'cruiserweight', 191],
    ['Trevor Murdoch', 'heavyweight', 241],
    ['Triple H', 'heavyweight', 255],
    ['Val Venis', 'light_heavyweight', null],
    ['Viscera', 'super_heavyweight', null],
];

$smackdown = [
    ['Batista', 'heavyweight', 290],
    ['Bobby Lashley', 'heavyweight', 273],
    ['Booker T', 'heavyweight', 253],
    ['Brian Kendrick', 'cruiserweight', null],
    ['Chris Benoit', 'light_heavyweight', 220],
    ['Daivari', 'cruiserweight', 189],
    ['Eddie Guerrero', 'light_heavyweight', 221],
    ['Funaki', 'cruiserweight', 195],
    ['Hardcore Holly', 'heavyweight', 235],
    ['Heidenreich', 'super_heavyweight', 300],
    ['JBL', 'heavyweight', 280],
    ['Joey Mercury', 'light_heavyweight', 191],
    ['Johnny Nitro', 'light_heavyweight', 224],
    ['Mr. Kennedy', 'light_heavyweight', 235],
    ['Nunzio', 'cruiserweight', null],
    ['Orlando Jordan', 'heavyweight', 257],
    ['Paul London', 'cruiserweight', 180],
    ['Psicosis', 'cruiserweight', null],
    ['Randy Orton', 'heavyweight', 245],
    ['Rey Mysterio', 'cruiserweight', 175],
    ['Road Warrior Animal', 'heavyweight', null],
    ['Scotty 2 Hotty', 'light_heavyweight', 209],
    ['Stevie Richards', 'light_heavyweight', 220],
    ['Super Crazy', 'cruiserweight', null],
    ['Undertaker', 'super_heavyweight', 309],
    ['Vito', 'heavyweight', 255],
    ['William Regal', 'heavyweight', 240],
];

$freeAgents = [
    ['A-Train', 'super_heavyweight', 331],  // 2004 addition
    ['Al Snow', 'light_heavyweight', 235],
    ['Balls Mahoney', 'super_heavyweight', 305],  // 2006 debut
    ['Billy Gunn', 'heavyweight', 260],  // 2004 addition
    ['Billy Kidman', 'cruiserweight', 195],
    ['Brock Lesnar', 'heavyweight', 286],  // 2004 addition
    ['Bubba Ray Dudley', 'heavyweight', null],
    ['Charlie Haas', 'heavyweight', 249],
    ['Chavo Classic', 'light_heavyweight', 229],  // 2004 addition
    ['Chavo Guerrero', 'cruiserweight', 215],
    ['Chris Jericho', 'light_heavyweight', 225],
    ['Christian', 'light_heavyweight', 227],
    ['Chuck Palumbo', 'heavyweight', null],
    ['CM Punk', 'light_heavyweight', 218],  // 2006 debut; class set by choice: billed under 221 lbs
    ['Cody Rhodes', 'light_heavyweight', 220],  // 2007 debut; class set by choice: billed under 221 lbs
    ['Colin Delaney', 'cruiserweight', 172],  // 2007 debut
    ['Curt Hawkins', 'light_heavyweight', 223],  // 2007 debut
    ['D-Von Dudley', 'heavyweight', null],
    ['Danny Basham', 'heavyweight', 250],
    ['Dave Taylor', 'heavyweight', 256],  // 2006 debut
    ['Deuce', 'light_heavyweight', null],
    ['Dolph Ziggler', 'light_heavyweight', 223],
    ['Domino', 'light_heavyweight', null],
    ['Doug Basham', 'heavyweight', 245],
    ['Drew McIntyre', 'heavyweight', 265],  // 2007 debut
    ['Elijah Burke', 'light_heavyweight', 230],  // 2006 debut
    ['Festus', 'heavyweight', 290],  // 2006 debut
    ['Frankie Kazarian', 'cruiserweight', 215],
    ['Goldberg', 'heavyweight', 285],  // 2004 addition
    ['Goldust', 'light_heavyweight', 232],
    ['Grandmaster Sexay', 'cruiserweight', 213],  // 2004 addition
    ['Gymini Jake', 'super_heavyweight', 304],  // 2006 debut
    ['Gymini Jesse', 'super_heavyweight', 304],  // 2006 debut
    ['Hornswoggle', 'cruiserweight', 142],  // 2006 debut
    ['Idol Stevens', 'heavyweight', 247],  // 2006 debut
    ['James Curtis', 'light_heavyweight', 235],  // 2006 debut
    ['Jamie Noble', 'cruiserweight', 198],
    ['Jeff Hardy', 'light_heavyweight', 225],
    ['Jesse', 'cruiserweight', 220],  // 2007 debut
    ['Jimmy Wang Yang', 'cruiserweight', 206],
    ['JTG', 'cruiserweight', null],
    ['Justin Credible', 'light_heavyweight', 225],  // 2006 debut
    ['Juventud', 'cruiserweight', 165],
    ['Kenzo Suzuki', 'heavyweight', 260],
    ['Kevin Thorn', 'heavyweight', 270],  // 2006 debut
    ['Kid Kash', 'cruiserweight', 202],
    ['Kofi Kingston', 'cruiserweight', 212],  // 2007 debut
    ['Lance Storm', 'light_heavyweight', 231],
    ['Luther Reigns', 'heavyweight', 285],
    ['Marcus Cor Von', 'heavyweight', 265],  // 2007 debut
    ['Mark Henry', 'super_heavyweight', null],
    ['Mark Jindrak', 'heavyweight', 265],
    ['Marty Jannetty', 'light_heavyweight', 234],
    ['Matt Hardy', 'light_heavyweight', 235],
    ['Matt Morgan', 'super_heavyweight', 308],
    ['Maven', 'light_heavyweight', 225],
    ['Muhammad Hassan', 'heavyweight', 245],
    ['MVP', 'heavyweight', null],
    ['Paul Burchill', 'heavyweight', 247],
    ['Rhino', 'heavyweight', 295],
    ['Rico', 'light_heavyweight', 230],  // 2004 addition
    ['Rikishi', 'ultra_heavyweight', 425],  // 2004 addition
    ['Robbie McAllister', 'heavyweight', null],
    ['Rodney Mack', 'heavyweight', 240],  // 2006 debut
    ['Rory McAllister', 'heavyweight', null],
    ['Rosey', 'super_heavyweight', 375],
    ['Santino Marella', 'light_heavyweight', 233],  // 2007 debut
    ['Scott Steiner', 'heavyweight', 276],  // 2004 addition
    ["Sean O'Haire", 'heavyweight', 270],  // 2004 addition
    ['Shad', 'heavyweight', null],
    ['Shannon Moore', 'cruiserweight', 202],
    ['Simon Dean', 'cruiserweight', 220],
    ['Spike Dudley', 'cruiserweight', 150],
    ['Sylvain Grenier', 'heavyweight', 250],  // House rule (2026-11-15): Stacy Keibler takes his Smackdown spot.
    ['Sylvester Terkay', 'super_heavyweight', 320],  // 2006 debut
    ['Test', 'heavyweight', 285],  // 2006 debut
    ['The Blue Meanie', 'super_heavyweight', 323],
    ['The Boogeyman', 'heavyweight', null],
    ['The Great Khali', 'ultra_heavyweight', 420],
    ['The Miz', 'light_heavyweight', 221],  // 2006 debut
    ['The Sandman', 'heavyweight', 240],  // 2006 debut
    ['Tommy Dreamer', 'heavyweight', null],
    ['Tony Mamaluke', 'cruiserweight', 219],  // 2006 debut
    ['Tyson Tomko', 'heavyweight', 292],
    ['Ultimo Dragon', 'cruiserweight', 185],  // 2004 addition
    ['Umaga', 'super_heavyweight', null],
    ['Vladimir Kozlov', 'super_heavyweight', 302],  // 2006 debut
    ['Zack Ryder', 'light_heavyweight', 230],  // 2007 debut
];

$rawDivas = [
    'Lita',
    'Mickie James',
    'Torrie Wilson',
    'Trish Stratus',
    'Victoria',
];
$smackdownDivas = [
    'Melina',
    'Sharmell',
    'Stacy Keibler',
];
$freeAgentDivas = [
    'Ariel',
    'Ashley Massaro',
    'Beth Phoenix',
    'Brooke Adams',
    'Candice Michelle',
    'Cherry',
    'Christy Hemme',
    'Dawn Marie',
    'Francine',
    'Gail Kim',
    'Hiroko',
    'Ivory',
    'Jacqueline',
    'Jazz',
    'Jillian Hall',
    'Joy Giovanni',
    'Kelly Kelly',
    'Kristal',
    'Layla',
    'Mae Young',
    'Maria',
    'Michelle McCool',
    'Molly Holly',
    'Nidia',
    'Sable',
    'Shaniqua',
    'The Fabulous Moolah',
    'Trinity',
    'Vickie Guerrero',
];

$legends = [
    ['Andre The Giant', 'ultra_heavyweight', 520],
    ['Arn Anderson', 'heavyweight', 255],
    ['Bam Bam Bigelow', 'super_heavyweight', null],
    ['Bob Backlund', 'light_heavyweight', 234],
    ['Bob Orton', 'heavyweight', null],
    ['Bret Hart', 'heavyweight', 234],
    ['British Bulldog', 'heavyweight', 253],
    ['Brutus Beefcake', 'heavyweight', null],
    ['Dean Malenko', 'cruiserweight', 212],
    ['Dusty Rhodes', 'heavyweight', null],
    ['Faarooq', 'heavyweight', 270],
    ['Fit Finlay', 'light_heavyweight', 233],
    ['Gerald Brisco', 'light_heavyweight', 209],
    ['Hulk Hogan', 'super_heavyweight', 303],
    ['Jack Brisco', 'light_heavyweight', 234],
    ['Jake Roberts', 'heavyweight', 253],
    ['Jerry Lawler', 'light_heavyweight', null],
    ['Jim Neidhart', 'heavyweight', null],
    ['Jimmy Hart', 'cruiserweight', 166],
    ['Jimmy Snuka', 'light_heavyweight', 235],
    ['Junkyard Dog', 'heavyweight', 280],
    ['Michael Hayes', 'heavyweight', 255],
    ['Mr. McMahon', 'heavyweight', 248],
    ['Mr. Perfect', 'heavyweight', null],
    ['Pat Patterson', 'light_heavyweight', 237],
    ['Rick Rude', 'heavyweight', null],
    ['Ricky Steamboat', 'light_heavyweight', 235],
    ['Road Warrior Hawk', 'heavyweight', null],
    ['Roddy Piper', 'heavyweight', null],
    ['Sabu', 'light_heavyweight', null],
    ['Sgt. Slaughter', 'super_heavyweight', null],
    ['Shane McMahon', 'heavyweight', null],
    ['Stone Cold Steve Austin', 'heavyweight', 252],
    ['Tazz', 'heavyweight', null],
    ['Ted DiBiase', 'heavyweight', 260],
    ['Terry Funk', 'heavyweight', null],
    ['The Brooklyn Brawler', 'heavyweight', 248],
    ['The Rock', 'heavyweight', 275],
];

/*
| Salaries (house rule, 2026-11-15): a month's pay by roster type. A
| contract costs this × its months (1, 3, 6, 9 or 12), paid weekly.
*/
$salary = ['superstar' => 40_000, 'diva' => 30_000, 'legend' => 75_000];

$rows = [];

foreach ([['raw', $raw], ['smackdown', $smackdown], [null, $freeAgents]] as [$brand, $list]) {
    foreach ($list as [$name, $class, $weight]) {
        $rows[] = ['name' => $name, 'roster_type' => 'superstar', 'placeholder_brand' => $brand, 'weight_class' => $class, 'billed_weight_lb' => $weight, 'monthly_salary' => $salary['superstar']];
    }
}

foreach ([['raw', $rawDivas], ['smackdown', $smackdownDivas], [null, $freeAgentDivas]] as [$brand, $list]) {
    foreach ($list as $name) {
        $rows[] = ['name' => $name, 'roster_type' => 'diva', 'placeholder_brand' => $brand, 'weight_class' => 'featherweight', 'billed_weight_lb' => null, 'monthly_salary' => $salary['diva']];
    }
}

foreach ($legends as [$name, $class, $weight]) {
    $rows[] = ['name' => $name, 'roster_type' => 'legend', 'placeholder_brand' => null, 'weight_class' => $class, 'billed_weight_lb' => $weight, 'monthly_salary' => $salary['legend']];
}

return [
    'wrestlers' => $rows,
    'specialties' => [
        'Bam Bam Bigelow' => ['hardcore'],
        'Bret Hart' => ['submission', 'iron_man'],
        'Bubba Ray Dudley' => ['table', 'hardcore'],
        'Chris Benoit' => ['submission'],
        'Chris Masters' => ['submission'],
        'Christian' => ['tlc', 'ladder'],
        'D-Von Dudley' => ['table', 'hardcore'],
        'Eddie Guerrero' => ['first_blood', 'backstage_brawl'],
        'Edge' => ['tlc', 'ladder'],
        'Hardcore Holly' => ['hardcore'],
        'JBL' => ['first_blood'],
        'Jeff Hardy' => ['tlc', 'ladder'],
        'Joey Mercury' => ['ladder'],
        'John Cena' => ['submission', 'backstage_brawl'],
        'Johnny Nitro' => ['ladder'],
        'Kane' => ['first_blood'],
        'Kurt Angle' => ['submission'],
        'Matt Hardy' => ['tlc', 'ladder'],
        'Mick Foley' => ['hardcore', 'hell_in_a_cell'],
        'Rhino' => ['hardcore'],
        'Rob Van Dam' => ['hardcore'],
        'Sabu' => ['hardcore'],
        'Shawn Michaels' => ['ladder', 'iron_man'],
        'Shelton Benjamin' => ['ladder'],
        'Spike Dudley' => ['table', 'hardcore'],
        'Stevie Richards' => ['hardcore'],
        'Stone Cold Steve Austin' => ['first_blood'],
        'Terry Funk' => ['hardcore'],
        'The Blue Meanie' => ['first_blood', 'hardcore'],
        'The Great Khali' => ['steel_cage'],
        'Tommy Dreamer' => ['hardcore'],
        'Triple H' => ['hell_in_a_cell'],
        'Undertaker' => ['hell_in_a_cell'],
    ],
];
