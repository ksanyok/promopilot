<?php
return [
	'slug' => 'writeas',
	'title' => 'Write.as',
	'description' => 'Write.as — минималистичная платформа для публикации заметок и статей в Markdown без регистрации и лишних форм.',
	'handler' => __DIR__ . '/writeas.js',
	'handler_type' => 'node',
	'priority' => 100,
	'level' => '1,2,3',
	'meta' => [
		'supports' => ['articles', 'longform', 'markdown'],
		'docs' => 'https://write.as/new',
		'url' => 'https://write.as',
		'regions' => [
			'Global', 'US', 'EU', 'CIS', 'UK', 'MENA', 'APAC', 'LATAM', 'CA', 'AU', 'NZ'
		],
		'topics' => [
			'General', 'Blogging', 'Creative Writing', 'Lifestyle', 'Productivity',
			'Education', 'Marketing/SEO', 'Personal Development', 'Culture', 'Travel'
		],
	],
];

