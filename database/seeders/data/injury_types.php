<?php

/*
| Injury labels (§6). Minor rows: [label, min, max, escalates_to|null].
| Serious rows: [label, min, max].
*/

return [
    'minor' => [
        ['Sprained Wrist', 2, 4, 'Broken Wrist'],
        ['Back Spasms', 1, 2, 'Herniated Disc'],
        ['Sprained Ankle', 2, 4, 'Broken Ankle'],
        ['Bruised Ribs', 2, 4, 'Broken Ribs'],
        ['Pulled Hamstring', 3, 5, 'Torn Hamstring'],
        ['Groin Strain', 2, 4, 'Torn Groin'],
        ['Neck Stinger', 1, 2, 'Neck Injury (Surgery)'],
        ['Shoulder Strain', 2, 4, 'Torn Rotator Cuff'],
        ['Knee Contusion', 2, 4, 'Torn Meniscus'],
        ['Dislocated Finger', 1, 2, 'Broken Hand'],
        ['Laceration (Stitches)', 1, 2, null],
        ['Mild Concussion', 3, 5, 'Severe Concussion'],
        ['Arm Hematoma', 2, 4, 'Torn Triceps'],
    ],
    'serious' => [
        ['Broken Wrist', 6, 12],
        ['Broken Hand', 5, 8],
        ['Broken Ankle', 8, 16],
        ['Broken Ribs', 5, 8],
        ['Herniated Disc', 8, 26],
        ['Severe Concussion', 5, 12],
        ['Separated Shoulder', 5, 10],
        ['Torn Rotator Cuff', 20, 36],
        ['Torn Meniscus', 5, 12],
        ['Torn MCL', 6, 12],
        ['Torn ACL', 36, 52],
        ['Torn Hamstring', 8, 16],
        ['Torn Groin', 6, 12],
        ['Torn Pectoral', 20, 30],
        ['Torn Biceps', 16, 26],
        ['Torn Triceps', 20, 30],
        ['Torn Quadriceps', 26, 52],
        ['Torn Latissimus Dorsi', 16, 26],
        ['Torn Achilles Tendon', 36, 52],
        ['Torn Patellar Tendon', 26, 40],
        ['Fractured Vertebrae', 26, 52],
        ['Neck Injury (Surgery)', 36, 52],
    ],
];
