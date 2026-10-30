<?php

/*
| Real-life factions (house rule) whose members are all in the seeded roster.
| Columns: name, type, date formed, date disbanded (null = still together),
| members. Dates are real-life history, shown on the Factions page only:
| every seeded faction is active from week 1 of every save. 2–5 members.
*/

return [
    // Tag teams
    ['APA', 'tag_team', '1998-11-16', '2004-03-18', ['Faarooq', 'JBL']],
    ['Christian & Tomko', 'tag_team', '2004-04-19', '2005-10-24', ['Christian', 'Tyson Tomko']],
    ['Angle & Reigns', 'tag_team', '2004-03-25', '2005-01-01', ['Kurt Angle', 'Luther Reigns']],
    ['Jericho & Christian', 'tag_team', '2002-09-23', '2004-02-16', ['Chris Jericho', 'Christian']],
    ['The Dudley Boyz', 'tag_team', '1996-02-03', '2005-08-05', ['Bubba Ray Dudley', 'D-Von Dudley', 'Spike Dudley']],
    ['MNM', 'tag_team', '2005-04-28', '2007-03-05', ['Johnny Nitro', 'Joey Mercury', 'Melina']],
    ['The Basham Brothers', 'tag_team', '2003-02-20', '2005-07-01', ['Danny Basham', 'Doug Basham']],
    ["The World's Greatest Tag Team", 'tag_team', '2003-06-19', '2004-03-22', ['Shelton Benjamin', 'Charlie Haas']],
    ['Cryme Tyme', 'tag_team', '2006-10-02', '2010-04-26', ['JTG', 'Shad']],
    ['The Highlanders', 'tag_team', '2006-03-27', '2008-03-03', ['Robbie McAllister', 'Rory McAllister']],
    ["Deuce 'n Domino", 'tag_team', '2007-01-19', '2008-09-01', ['Deuce', 'Domino', 'Cherry']],
    ['3-Minute Warning', 'tag_team', '2002-07-22', '2003-06-16', ['Rosey', 'Umaga']],
    ['La Résistance', 'tag_team', '2003-05-19', '2006-03-13', ['Rene Dupree', 'Sylvain Grenier', 'Rob Conway']],
    ['The Rockers', 'tag_team', '1986-06-01', '1992-01-12', ['Shawn Michaels', 'Marty Jannetty']],
    ['Brothers of Destruction', 'tag_team', '1998-08-24', '2020-11-22', ['Undertaker', 'Kane']],
    ["Rock 'n' Sock Connection", 'tag_team', '1999-08-30', '2004-03-14', ['The Rock', 'Mick Foley']],
    ['Two-Man Power Trip', 'tag_team', '2001-04-02', '2001-05-21', ['Stone Cold Steve Austin', 'Triple H']],
    ['Rated-RKO', 'tag_team', '2006-10-02', '2007-04-02', ['Edge', 'Randy Orton']],
    ['Los Guerreros', 'tag_team', '2002-10-03', '2004-01-22', ['Eddie Guerrero', 'Chavo Guerrero']],
    ['The Brisco Brothers', 'tag_team', '1973-01-01', '1984-04-09', ['Jack Brisco', 'Gerald Brisco']],
    ['The Stooges', 'tag_team', '1998-02-01', '2000-12-01', ['Pat Patterson', 'Gerald Brisco']],
    ['Blue World Order', 'tag_team', '1996-08-03', '1997-10-01', ['Stevie Richards', 'The Blue Meanie']],
    ['Edge & Christian', 'tag_team', '1998-06-22', '2001-04-30', ['Edge', 'Christian']],
    ['Hassan & Daivari', 'tag_team', '2004-12-13', '2005-07-24', ['Muhammad Hassan', 'Daivari']],

    // Stables
    ['The Legion of Doom', 'stable', '1983-06-01', '2006-01-17', ['Road Warrior Hawk', 'Road Warrior Animal', 'Heidenreich']],
    ['D-Generation X', 'stable', '1997-10-13', '2010-03-29', ['Triple H', 'Shawn Michaels', 'Rick Rude']],
    ['Evolution', 'stable', '2003-01-20', '2005-10-03', ['Triple H', 'Ric Flair', 'Batista', 'Randy Orton']],
    ['The Hart Foundation', 'stable', '1985-01-01', '1997-11-09', ['Bret Hart', 'Jim Neidhart', 'British Bulldog']],
    ['Team Xtreme', 'stable', '2000-01-17', '2001-12-24', ['Matt Hardy', 'Jeff Hardy', 'Lita']],
    ['Team Angle', 'stable', '2002-12-26', '2003-06-19', ['Kurt Angle', 'Shelton Benjamin', 'Charlie Haas']],
    ['The Un-Americans', 'stable', '2002-06-20', '2002-09-30', ['Christian', 'Lance Storm', 'William Regal']],
    ['Right to Censor', 'stable', '2000-06-19', '2001-05-21', ['Stevie Richards', 'Val Venis', 'Ivory']],
    ['The Radicalz', 'stable', '2000-01-31', '2001-04-01', ['Chris Benoit', 'Eddie Guerrero', 'Dean Malenko']],
    ['Ministry of Darkness', 'stable', '1998-10-05', '1999-06-28', ['Undertaker', 'JBL', 'Viscera', 'Edge', 'Christian']],
    ["King Booker's Court", 'stable', '2006-05-26', '2007-04-06', ['Booker T', 'Fit Finlay', 'William Regal', 'Sharmell']],
    ['The Four Horsemen', 'stable', '1986-01-01', '1999-09-01', ['Ric Flair', 'Arn Anderson']],
    ['Nation of Domination', 'stable', '1996-11-17', '1998-11-28', ['The Rock', 'Mark Henry', 'Faarooq']],
    ['Filthy Animals', 'stable', '1999-08-16', '2001-03-26', ['Rey Mysterio', 'Billy Kidman', 'Eddie Guerrero', 'Juventud', 'Torrie Wilson']],
    ['The Cabinet', 'stable', '2004-08-12', '2006-05-26', ['JBL', 'Orlando Jordan', 'Danny Basham', 'Doug Basham', 'Jillian Hall']],
    ['Full Blooded Italians', 'stable', '1995-06-01', '2006-04-01', ['Nunzio', 'Vito', 'Chuck Palumbo']],
    ['The Mexicools', 'stable', '2005-06-23', '2006-11-01', ['Psicosis', 'Super Crazy', 'Juventud']],
    ["Vince's Devils", 'stable', '2005-08-22', '2006-03-06', ['Candice Michelle', 'Torrie Wilson', 'Victoria']],
];
