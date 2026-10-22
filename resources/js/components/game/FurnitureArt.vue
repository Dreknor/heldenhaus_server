<script setup lang="ts">
withDefaults(defineProps<{ itemKey: string; icon: string; name?: string; master?: boolean }>(), {
    name: 'Möbelstück',
    master: false,
});
</script>

<template>
    <svg viewBox="0 0 100 100" role="img" :aria-label="name" xmlns="http://www.w3.org/2000/svg" class="h-full w-full overflow-visible">
        <title>{{ name }}</title>
        <ellipse cx="50" cy="91" rx="36" ry="6" fill="#3c281e" opacity=".2" />
        <g stroke="#493326" stroke-width="3" stroke-linejoin="round" stroke-linecap="round">
            <template v-if="itemKey.includes('bunk_bed')">
                <path d="M20 21h60v15H20zm0 37h60v15H20z" fill="#9b5f3e" />
                <path d="M24 12h22q6 0 6 7v9H24zm0 37h22q6 0 6 7v9H24z" fill="#fff1d6" />
                <path d="M18 18v63m64-63v63M72 37v21M72 73v13m-44-13v13" fill="none" stroke-width="5" />
                <path d="M70 39 55 58m15-8L55 69m15-8L60 76" fill="none" stroke="#d6a46c" stroke-width="4" />
            </template>
            <template v-else-if="itemKey.includes('bed')">
                <path d="M16 58h68v21H16z" fill="#9b5f3e" />
                <path d="M19 47h24q7 0 7 8v9H19z" fill="#fff1d6" />
                <path d="M49 50h33q5 0 5 7v7H49z" fill="#e98e9d" />
                <path d="M16 58h68v9H16z" fill="#79503a" />
                <path d="M20 79v8m60-8v8" fill="none" stroke-width="5" />
                <path d="M22 50q4-7 12 0" fill="none" stroke="#e5bb8e" />
            </template>
            <template v-else-if="itemKey.includes('chair') || itemKey.includes('beanbag') || itemKey.includes('cushion')">
                <path d="M29 47q0-19 21-19t21 19v27H29z" :fill="itemKey.includes('beanbag') ? '#8c74ce' : '#d98b50'" />
                <path d="M23 49q-5 0-5 7v14q0 5 6 5h7V49zm54 0q5 0 5 7v14q0 5-6 5h-6V49z" fill="#bd7042" />
                <path d="M34 74v12m32-12v12" fill="none" stroke-width="5" />
                <path d="M39 43q10-8 22 0" fill="none" stroke="#f3c18b" />
            </template>
            <template v-else-if="itemKey.includes('table') || itemKey.includes('desk') || itemKey.includes('counter') || itemKey.includes('stove')">
                <path d="M14 42h72v13H14z" :fill="itemKey.includes('stove') ? '#64748b' : '#bd7945'" />
                <path d="M22 55v29m56-29v29" fill="none" stroke-width="6" />
                <path d="M25 36h50" fill="none" stroke="#e9b779" stroke-width="4" />
                <template v-if="itemKey.includes('stove')">
                    <circle cx="38" cy="48" r="4" fill="#f8fafc" />
                    <circle cx="62" cy="48" r="4" fill="#f8fafc" />
                </template>
                <template v-else-if="itemKey.includes('dining')">
                    <path d="M10 57h16v13H10zm64 0h16v13H74z" fill="#ddaa70" />
                </template>
                <template v-else><path d="M37 38h26l-4-10H42z" fill="#f4dfba" /></template>
            </template>
            <template v-else-if="itemKey.includes('sofa')">
                <path d="M22 48q0-10 10-10h36q10 0 10 10v28H22z" fill="#598d77" />
                <path d="M16 49q-7 0-7 8v16q0 5 6 5h10V49zm68 0q7 0 7 8v16q0 5-6 5H75V49z" fill="#46745f" />
                <path d="M31 43h17v24H31zm21 0h17v24H52z" fill="#78ad91" />
                <path d="M24 78v8m52-8v8" fill="none" stroke-width="5" />
            </template>
            <template
                v-else-if="
                    itemKey.includes('bookshelf') || itemKey.includes('wall_shelf') || itemKey.includes('wardrobe') || itemKey.includes('fridge')
                "
            >
                <path d="M23 13h54v73H23z" :fill="itemKey.includes('fridge') ? '#c7e3e8' : '#a96e43'" />
                <path d="M18 12h64v7H18z" fill="#d59a61" />
                <template v-if="itemKey.includes('bookshelf') || itemKey.includes('wall_shelf')">
                    <path d="M25 38h50M25 62h50" stroke="#64432f" stroke-width="4" />
                    <path d="M30 22v14m10-14v14m12-14v14m12-14v14M31 42v17m12-17v17m10-17v17m14-17v17" stroke="#edbf69" stroke-width="7" />
                </template>
                <template v-else>
                    <path d="M50 20v64" stroke="#70492f" />
                    <path d="M68 30v12m-36 18v12" stroke="#f3d7a7" stroke-width="4" />
                </template>
                <path d="M29 86v3m42-3v3" fill="none" stroke-width="4" />
            </template>
            <template v-else-if="itemKey.includes('plant') || itemKey.includes('flower') || itemKey.includes('cactus') || itemKey.includes('bonsai')">
                <path d="M33 62h34l-5 24H38z" fill="#c86e4d" />
                <path d="M50 62V31m0 20Q27 50 29 33q16-3 21 15m0-8q19 0 20-18-15-2-20 12m-1-6q-9-9-2-20 12 7 5 20" fill="#62a96c" />
                <circle cx="51" cy="17" r="4" fill="#f5ca60" />
                <path d="M40 69h20" stroke="#e78b64" fill="none" />
            </template>
            <template v-else-if="itemKey.includes('teddy') || itemKey.includes('pet_bed')">
                <template v-if="itemKey.includes('pet_bed')">
                    <path d="M18 65q2-23 32-23t32 23q-3 20-32 20T18 65z" fill="#d99a68" />
                    <path d="M29 62q3-11 21-11t21 11q-2 13-21 13T29 62z" fill="#f5d5a9" />
                    <path d="M42 61q8-6 16 0l-2 8h-12z" fill="#e7a5a7" />
                </template>
                <template v-else>
                    <circle cx="50" cy="29" r="19" fill="#b8794d" />
                    <circle cx="35" cy="16" r="8" fill="#d49a69" />
                    <circle cx="65" cy="16" r="8" fill="#d49a69" />
                    <ellipse cx="50" cy="61" rx="24" ry="22" fill="#c98a5c" />
                    <ellipse cx="50" cy="36" rx="10" ry="7" fill="#f2d4aa" />
                    <circle cx="43" cy="28" r="2" fill="#493326" stroke="none" />
                    <circle cx="57" cy="28" r="2" fill="#493326" stroke="none" />
                    <path d="M47 36q3 3 6 0" fill="none" />
                    <ellipse cx="50" cy="60" rx="8" ry="10" fill="#f2d4aa" />
                </template>
            </template>
            <template v-else-if="itemKey.includes('gaming_corner')">
                <path d="M11 53h78v11H11z" fill="#a96e43" />
                <path d="M19 64v22m62-22v22" fill="none" stroke-width="5" />
                <rect x="30" y="16" width="41" height="36" rx="4" fill="#38435e" />
                <rect x="35" y="21" width="31" height="25" rx="2" fill="#8ed6df" />
                <path d="m38 41 8-10 7 6 6-9 5 13z" fill="#5ca76d" stroke="none" />
                <path d="M45 53v6m12-6v6" fill="none" stroke-width="4" />
                <path d="M37 70h28q4 0 4 5v4H33v-4q0-5 4-5z" fill="#8b74c9" />
                <circle cx="46" cy="75" r="2" fill="#f8e28a" stroke="none" />
                <circle cx="57" cy="75" r="2" fill="#f8e28a" stroke="none" />
            </template>
            <template
                v-else-if="
                    itemKey.includes('painting') ||
                    itemKey.includes('poster') ||
                    itemKey.includes('postcard') ||
                    itemKey.includes('map') ||
                    itemKey.includes('photo') ||
                    itemKey.includes('pinboard') ||
                    itemKey.includes('sticker')
                "
            >
                <path d="M20 13h60v64H20z" fill="#a66b42" />
                <path d="M26 19h48v51H26z" fill="#f6dfaa" />
                <circle cx="60" cy="33" r="8" fill="#f3bd55" stroke="none" />
                <path d="M29 64 45 43l10 12 7-8 13 17z" fill="#6d9b71" stroke="none" />
                <path d="M20 78h60" fill="none" stroke-width="4" />
            </template>
            <template v-else-if="itemKey.includes('window')">
                <path d="M18 13h64v72H18z" fill="#91613f" />
                <path d="M25 20h50v58H25z" fill="#8fd4ea" />
                <path d="M50 20v58M25 49h50" stroke="#f4e0bd" stroke-width="5" />
                <path d="M27 71q12-15 24-4 12-15 22-1v12H27z" fill="#7dbb76" stroke="none" />
                <path d="M18 13h64v7H18z" fill="#d3a06e" />
            </template>
            <template v-else-if="itemKey.includes('chest') || itemKey.includes('toy_box') || itemKey.includes('basket')">
                <path d="M19 42h62v41H19z" fill="#b97842" />
                <path d="M16 35q0-9 9-9h50q9 0 9 9v12H16z" fill="#d29752" />
                <path d="M20 51h60M50 48v13" fill="none" stroke="#75492d" stroke-width="4" />
                <path d="M43 58h14v12H43z" fill="#f5cf72" />
                <path d="M27 83v4m46-4v4" fill="none" stroke-width="4" />
            </template>
            <template
                v-else-if="
                    itemKey.includes('banner') || itemKey.includes('garland') || itemKey.includes('fairy_lights') || itemKey.includes('cobweb')
                "
            >
                <path d="M13 23q37 26 74 0" fill="none" stroke="#72503b" stroke-width="3" />
                <template v-if="itemKey.includes('cobweb')">
                    <path
                        d="M50 24v50M50 24 22 67m28-43 28 43M50 24 29 40h42L50 24 36 56h28L50 24 43 68h14z"
                        fill="none"
                        stroke="#e9e8ed"
                        stroke-width="2"
                    />
                </template>
                <template v-else>
                    <path d="M20 32v24l10-7 10 7V43m10 1v24l10-7 10 7V37m10-5v24l7-5" fill="#e88787" />
                    <circle cx="24" cy="39" r="4" fill="#f3ca65" />
                    <circle cx="50" cy="47" r="4" fill="#87c8ce" />
                    <circle cx="76" cy="39" r="4" fill="#b891d2" />
                </template>
            </template>
            <template v-else-if="itemKey.includes('clock')">
                <path d="M50 11v8m-29 4 7 5m44-5-7 5" fill="none" stroke-width="4" />
                <path d="M22 24h56v58H22z" fill="#a86b42" />
                <circle cx="50" cy="48" r="18" fill="#fff3d4" />
                <path d="M50 35v14l10 6" fill="none" stroke-width="3" />
                <path d="M40 72q10-9 20 0v10H40z" fill="#b56f45" />
                <circle cx="50" cy="75" r="3" fill="#f4d173" />
            </template>
            <template v-else-if="itemKey.includes('mirror')">
                <path d="M50 10q27 0 27 29v25q0 24-27 24T23 64V39q0-29 27-29z" fill="#bfdae1" />
                <path d="M50 17q20 0 20 23v22q0 19-20 19T30 62V40q0-23 20-23z" fill="#e8f7f8" />
                <path d="M39 29q5-7 12-7m-14 45q3 8 10 9" fill="none" stroke="#fff" stroke-width="4" />
                <path d="M37 84h26" fill="none" stroke-width="5" />
            </template>
            <template v-else-if="itemKey.includes('hammock')">
                <path d="M12 19v66m76-66v66M12 26h17m47 0h12" fill="none" stroke="#8a5b3d" stroke-width="5" />
                <path d="M25 27q25 51 50 0l-6 32q-19 16-38 0z" fill="#e98e9d" />
                <path d="M34 47q16 15 32 0" fill="none" stroke="#f7c1c6" stroke-width="4" />
            </template>
            <template v-else-if="itemKey.includes('piano')">
                <path d="M15 33h70v46H15z" fill="#31333e" />
                <path d="M20 39h60v22H20z" fill="#f7f3e8" />
                <path d="M29 39v14m12-14v14m12-14v14m12-14v14m12-14v14" stroke="#31333e" stroke-width="5" />
                <path d="M21 78v8m58-8v8" fill="none" stroke-width="5" />
                <path d="M20 27h60" fill="none" stroke="#d7b17b" stroke-width="5" />
            </template>
            <template v-else-if="itemKey.includes('easel')">
                <path d="M50 35 25 88m25-53 25 53m-37-24h24m-21-9h18" fill="none" stroke="#a86b42" stroke-width="5" />
                <path d="M24 12h52v46H24z" fill="#f3dfa9" />
                <path d="m27 52 15-20 10 10 8-9 13 19z" fill="#71a875" stroke="none" />
                <circle cx="63" cy="25" r="6" fill="#f4be5c" stroke="none" />
            </template>
            <template v-else-if="itemKey.includes('globe')">
                <circle cx="50" cy="39" r="28" fill="#75c7d8" />
                <path
                    d="M30 21q8-7 13-2l-3 8 8 3-3 9-9-2-5 9-8-4 3-12zm23 0 10 3 3 8-7 3-1 9-9-3-1-10 6-4zm-6 31 12-3 9 7-5 10-11 6-7-8z"
                    fill="#79ad6e"
                    stroke="none"
                />
                <path d="M39 65 32 84m29-19 7 19M50 67v19m-21 0h42" fill="none" stroke-width="4" />
            </template>
            <template v-else-if="itemKey.includes('fountain')">
                <path d="M18 67q32 13 64 0l-7 17H25z" fill="#8fbcc2" />
                <path d="M33 61h34L61 51H39z" fill="#aacbd0" />
                <path d="M43 51h14V38H43z" fill="#b9dce0" />
                <path d="M50 38q-14-12 0-24 14 12 0 24z" fill="#70c9df" />
                <path d="M32 84v5m36-5v5" fill="none" stroke-width="4" />
                <path d="M25 71q25 7 50 0" fill="none" stroke="#e8ffff" stroke-width="2" />
            </template>
            <template v-else-if="itemKey.includes('vase')">
                <path d="M39 18h22q-2 10-1 16 15 17 11 35-3 17-21 17T29 69q-4-18 11-35 1-6-1-16z" fill="#87aebe" />
                <path d="M50 19v-8m0 27q-15-3-19-17m18 17q14-8 17-22m-17 22q-1-15 7-24" fill="none" stroke="#629b68" stroke-width="4" />
                <circle cx="31" cy="20" r="5" fill="#ec8e9a" />
                <circle cx="67" cy="17" r="5" fill="#f1ca60" />
                <circle cx="57" cy="13" r="4" fill="#a78ad4" />
            </template>
            <template v-else-if="itemKey.includes('cat_tree')">
                <path d="M45 30h13v55H45z" fill="#bd895b" />
                <path d="M20 54h61v10H20zM29 36h47v9H29z" fill="#d5a16a" />
                <path d="M15 82q0-10 12-10h14v14H15zm45 0q0-10 12-10h14v14H60z" fill="#c8905e" />
                <path d="M49 30q-8-12 1-22 11 4 8 17" fill="#72a878" />
                <circle cx="55" cy="24" r="4" fill="#e2b34f" />
            </template>
            <template v-else-if="itemKey.includes('pumpkin') || itemKey.includes('snowman') || itemKey.includes('advent_wreath')">
                <template v-if="itemKey.includes('pumpkin')">
                    <path d="M50 30q-25-18-32 7-7 34 32 38 39-4 32-38-7-25-32-7z" fill="#f39b45" />
                    <path d="M50 31q-11 14 0 43m0-43q11 14 0 43" fill="none" stroke="#d97930" stroke-width="3" />
                    <path d="M48 29q-2-12 8-17" fill="none" stroke="#638c4c" stroke-width="5" />
                    <path d="m35 51 6 5 6-5m9 0 6 5 6-5" fill="none" stroke="#75452c" stroke-width="3" />
                </template>
                <template v-else-if="itemKey.includes('snowman')">
                    <circle cx="50" cy="63" r="24" fill="#f6fbff" />
                    <circle cx="50" cy="31" r="17" fill="#f6fbff" />
                    <circle cx="44" cy="29" r="2" fill="#39485c" stroke="none" />
                    <circle cx="56" cy="29" r="2" fill="#39485c" stroke="none" />
                    <path d="m50 34 13 4-13 4z" fill="#ef924b" />
                    <path d="M38 54h24m-21 12h18" fill="none" stroke="#4a5666" stroke-width="3" />
                    <path d="M35 18h30l-5-8H40z" fill="#59667d" />
                </template>
                <template v-else>
                    <circle cx="50" cy="53" r="27" fill="none" stroke="#4d9a61" stroke-width="9" />
                    <circle cx="31" cy="38" r="5" fill="#e65459" />
                    <circle cx="66" cy="38" r="5" fill="#e65459" />
                    <circle cx="35" cy="70" r="5" fill="#e7bd54" />
                    <circle cx="67" cy="67" r="5" fill="#e7bd54" />
                    <path d="M50 28v-8m-5 0h10" fill="none" stroke="#9e693c" stroke-width="4" />
                    <path d="M50 19q-8-8 0-13 8 5 0 13z" fill="#ffdc72" />
                </template>
            </template>
            <template v-else-if="itemKey.includes('fireplace')">
                <path d="M15 23h70v64H15z" fill="#9b6650" />
                <path d="M25 36h50v51H25z" fill="#493a40" />
                <path d="M34 79q-9-18 5-30 0 10 8 11-2-18 11-28 0 18 9 25 8 14-3 22z" fill="#f58b3d" stroke="none" />
                <path d="M11 21h78v9H11z" fill="#d1a17a" />
            </template>
            <template v-else-if="itemKey.includes('rug') || itemKey.includes('carpet')">
                <path d="M18 25h64v52H18z" fill="#be6272" />
                <path d="M25 32h50v38H25z" fill="#f2c46d" />
                <path d="m50 36 15 15-15 15-15-15z" fill="#7c5eaa" />
                <path d="M18 34h-5m5 12h-5m5 12h-5m5 12h-5m69-36h5m-5 12h5m-5 12h5m-5 12h5" stroke="#be6272" />
            </template>
            <template
                v-else-if="itemKey.includes('lamp') || itemKey.includes('candle') || itemKey.includes('chandelier') || itemKey.includes('lantern')"
            >
                <path d="M50 17v15m-9-1q9-9 18 0l-4 18H45z" fill="#f5c867" />
                <path d="M50 50v29m-13 7h26m-19-7h12" fill="none" stroke-width="5" />
                <path d="M50 36q-10 9 0 14 10-5 0-14" fill="#ff9b45" stroke="none" />
                <circle cx="50" cy="44" r="25" fill="#ffd86c" opacity=".13" stroke="none" />
            </template>
            <template v-else-if="itemKey.includes('aquarium')">
                <path d="M15 23h70v54H15z" fill="#66c8d7" />
                <path d="M19 28h62v41H19z" fill="#a8e9e5" />
                <path d="M22 66q14-13 27 0 13-13 28 0v7H22z" fill="#55a873" stroke="none" />
                <path d="M39 47q12-13 24 0-12 14-24 0m11 0 8-6v12z" fill="#f5a653" stroke="none" />
                <circle cx="28" cy="40" r="2" fill="#fff" stroke="none" />
                <path d="M23 80h54" fill="none" stroke-width="5" />
            </template>
            <template v-else-if="itemKey.includes('tree')">
                <path d="m50 12 26 31H61l20 22H19l20-22H24z" fill="#3e9a67" />
                <path d="M44 65h12v23H44z" fill="#9b603d" />
                <circle cx="50" cy="35" r="4" fill="#ffd75e" stroke="none" />
                <circle cx="35" cy="55" r="3" fill="#ef6674" stroke="none" />
                <circle cx="65" cy="58" r="3" fill="#6bb6ee" stroke="none" />
            </template>
            <template v-else-if="itemKey.includes('dragon') || itemKey.includes('statue')">
                <path d="M25 77h50l-6 10H31z" fill="#8c674b" />
                <path d="M49 27q-11-13-20-3l9 3q-11 8-9 20l9-5q-5 19 7 28l5-12q9 11 18 7l-9-10q13-8 8-21l-9 5q-1-11-9-15z" fill="#72a98a" />
                <path d="M38 34 24 25l4 18m34-12 12-13-2 20" fill="#80bd97" />
                <circle cx="56" cy="39" r="2.5" fill="#f8de77" stroke="none" />
            </template>
            <template v-else-if="itemKey.includes('trophy') || itemKey.includes('cup')">
                <path d="M34 18h32v22q0 18-16 22-16-4-16-22z" fill="#f3c34f" />
                <path d="M34 25H20q0 19 17 19m29-19h14q0 19-17 19" fill="none" stroke="#bc852f" stroke-width="6" />
                <path d="M50 62v15m-14 8h28v-8H36z" fill="#d39a39" />
                <path d="m50 27 3 7 7 .5-5.5 4.5 2 7-6.5-4-6.5 4 2-7-5.5-4.5 7-.5z" fill="#fff4bd" stroke="none" />
            </template>
            <template v-else>
                <path d="M20 36h60v42H20z" fill="#b77b4c" />
                <path d="M15 31h70v10H15z" fill="#dda765" />
                <path d="M27 78v9m46-9v9" fill="none" stroke-width="5" />
                <text x="50" y="67" text-anchor="middle" font-size="25" stroke="none">{{ icon }}</text>
            </template>
        </g>
        <g v-if="master" fill="#ffdf72" stroke="#8a621f" stroke-width=".8">
            <path d="m82 5 2.2 5.8L90 13l-5.8 2.2L82 21l-2.2-5.8L74 13l5.8-2.2z" />
            <path d="m15 12 1.4 3.6L20 17l-3.6 1.4L15 22l-1.4-3.6L10 17l3.6-1.4z" />
        </g>
    </svg>
</template>
