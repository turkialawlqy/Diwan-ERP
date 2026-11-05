<?php

/*
| Withholding tax on payments to non-residents (Saudi income tax law, Art. 68 and its regulations).
| category => [label, rate %]. The rates are the statutory ones at the time of writing; the company can
| override any of them with the setting `wht_rate_{category}` (Settings -> Withholding tax) when the law changes.
*/
return [
    'categories' => [
        'services' => ['خدمات (فنية، استشارية، أخرى)', 5],
        'rent' => ['إيجار معدات وأصول', 5],
        'interest' => ['فوائد وعوائد تمويل', 5],
        'insurance' => ['أقساط تأمين وإعادة تأمين', 5],
        'royalties' => ['إتاوات (حقوق ملكية)', 15],
        'management' => ['أتعاب إدارة', 20],
        'other' => ['مدفوعات أخرى لغير المقيم', 15],
    ],
];
