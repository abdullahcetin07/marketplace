/**
 * Curated category buying-guide + FAQ content (SEO/GEO).
 *
 * WHY THIS EXISTS: category pages are the hubs head/informational queries land on,
 * and an AI answer engine needs a factual, self-contained passage to quote. A short
 * "nasıl seçilir" guide + a few FAQ (rendered as text AND FAQPage schema) turns a bare
 * product grid into a citable page — for the ~dozen highest-traffic categories only.
 * Uncovered categories simply render no block (degrade cleanly).
 *
 * ⚖️ HEALTH-CLAIM LAW: cosmetics/supplements may NOT claim to treat/prevent disease
 * (Kozmetik & Sağlık Beyanı Yönetmeliği). Copy here stays descriptive/consumer-guidance
 * ("nasıl seçilir", "nelere dikkat"); supplements carry the "ilaç değildir" note. If you
 * add categories, keep this — no "tedavi eder / iyileştirir / önler".
 *
 * Keyed by the EXACT category slug (flat slug, ADR-059).
 */
export type CategoryGuide = {
  /** 2–5 sentence buying guide shown below the grid on page 1. */
  intro: string;
  /** 3–5 Q&As — rendered as an accordion AND as FAQPage JSON-LD. */
  faq: { q: string; a: string }[];
};

export const categoryContent: Record<string, CategoryGuide> = {
  'gunes-kremleri': {
    intro:
      'Güneş kremi, cildi güneşin UVA ve UVB ışınlarına karşı korumaya yardımcı olan günlük bakım ürünüdür. Seçerken üç şeye bakın: koruma faktörü (SPF), geniş spektrum (UVA+UVB) koruması ve cilt tipinize uygun doku. Yağlı ciltler su bazlı/matlaştırıcı formülleri, kuru ciltler nemlendirici formülleri tercih edebilir. Etkili koruma için yeterli miktarda uygulamak ve yoğun güneşte belirli aralıklarla tekrarlamak önemlidir.',
    faq: [
      { q: 'SPF 30 mu SPF 50 mi seçmeliyim?', a: 'SPF, güneşin yakıcı ışınlarına karşı koruma seviyesini gösterir; sayı büyüdükçe koruma artar. Uzun süre açık havada kalacaksanız veya cildiniz açık/hassassa daha yüksek faktör tercih edebilirsiniz. Hangi faktörü seçerseniz seçin, düzenli tekrar uygulama korumanın sürekliliği için önemlidir.' },
      { q: 'Geniş spektrum ne demek?', a: 'Geniş spektrum, ürünün hem UVA hem UVB ışınlarına karşı koruma sağladığını belirtir. Etikette "geniş spektrum" veya "UVA/UVB" ifadesini aramanız, dengeli bir koruma için pratik bir yoldur.' },
      { q: 'Güneş kremini ne sıklıkta yenilemeliyim?', a: 'Genel öneri, yoğun güneşte ve terleme/yüzme sonrasında belirli aralıklarla tekrar uygulamaktır. Kesin süre ürünün etiketinde belirtilir; su dirençli ürünlerde bile su ve havluyla temas sonrası yenilemek önerilir.' },
      { q: 'Yağlı ciltler için hangi doku uygun?', a: 'Yağlı ve karma ciltler genellikle su bazlı, jel veya matlaştırıcı ("oil-free") dokuları daha rahat kullanır. Kuru ciltler ise nemlendirici içerikli, krem dokulu ürünleri tercih edebilir. Seçim kişisel konfor ve cilt tipine göre değişir.' },
    ],
  },
  'cocuk-gunes-kremleri': {
    intro:
      'Çocuk güneş ürünleri, çocukların daha hassas cildi düşünülerek geliştirilen, genellikle yüksek koruma faktörlü güneş kremleridir. Seçerken yüksek SPF, geniş spektrum koruma, koku ve boya bakımından sade içerik ve kolay uygulanan (stick, losyon, sprey) formları değerlendirin. Ürünün hangi yaştan itibaren uygun olduğu etikette belirtilir; uygulama ve tekrar sıklığı için etiket yönergesini izleyin.',
    faq: [
      { q: 'Çocuk güneş kremi yetişkininkinden farklı mı?', a: 'Çocuklara yönelik ürünler genellikle daha yüksek koruma faktörü ve koku/boya açısından sade içerikle sunulur. Temel işlev aynıdır; fark, hassas cilde uygun formülasyon ve kullanım kolaylığıdır. Ürünün yaş uygunluğu için etiketi kontrol edin.' },
      { q: 'Bebeklerde güneş kremi kullanılır mı?', a: 'Ürünlerin hangi yaştan itibaren uygun olduğu etiketinde belirtilir; çok küçük bebeklerde gölge ve fiziksel korunma (şapka, kıyafet) öncelikli olarak önerilir. Kullanım öncesi etiket yönergesini ve gerekiyorsa bir sağlık uzmanının görüşünü dikkate alın.' },
      { q: 'Stick, losyon, sprey — hangisi pratik?', a: 'Stick formlar yüz, burun ve kulak gibi bölgelerde dağılmadan uygulama sağlar; losyon geniş alanlarda, sprey ise hızlı uygulamada kolaylık sunar. Çoğu ailede birden fazla form birlikte kullanılır. Tercih, kullanım alışkanlığına göre değişir.' },
    ],
  },
  'cilt-bakimi': {
    intro:
      'Cilt bakımı; temizleme, nemlendirme ve güneşe karşı koruma adımlarından oluşan günlük bir rutindir. Doğru ürünleri seçmenin ilk adımı cilt tipinizi (kuru, yağlı, karma, hassas) tanımaktır. Ardından ihtiyaca göre temizleyici, nemlendirici, göz çevresi ve güneş koruyucu ürünleri bir araya getirebilirsiniz. Yeni bir ürünü rutine eklerken içerik listesini okumak ve kademeli başlamak pratik bir yaklaşımdır.',
    faq: [
      { q: 'Temel cilt bakımı rutini nasıl olmalı?', a: 'Sade bir rutin genellikle sabah temizleme, nemlendirme ve güneş koruyucu; akşam ise temizleme ve nemlendirmeden oluşur. İhtiyaca göre serum veya göz kremi eklenebilir. Az sayıda, cilt tipinize uygun ürünle başlamak çoğu kişi için yeterlidir.' },
      { q: 'Cilt tipimi nasıl anlarım?', a: 'Temizlik sonrası cildiniz gün içinde parlıyorsa yağlı, geriliyor/pul pul oluyorsa kuru, T bölgesi yağlı yanaklar normalse karma olabilir. Kolayca kızaran/tahriş olan ciltler hassas kabul edilir. Emin değilseniz bir dermatoloğa danışabilirsiniz.' },
      { q: 'Ürünleri hangi sırayla uygulamalıyım?', a: 'Genel yaklaşım inceden yoğuna doğrudur: temizleyici, ardından su bazlı/serum, sonra nemlendirici ve en son (sabah) güneş koruyucu. Ürün etiketindeki kullanım önerisi önceliklidir.' },
    ],
  },
  'nemlendiriciler': {
    intro:
      'Nemlendiriciler, cildin nem dengesini korumaya yardımcı olan bakım ürünleridir. Seçerken cilt tipinizi ve dokusu tercihini göz önünde bulundurun: yağlı ciltler hafif, su bazlı (jel) formülleri; kuru ciltler daha zengin, krem dokulu ürünleri rahat kullanır. Hyalüronik asit ve gliserin gibi nem tutucu içerikler etiketlerde sık karşınıza çıkar. Temizlik sonrası uygulanması yaygın bir kullanımdır.',
    faq: [
      { q: 'Yağlı cilt nemlendirici kullanmalı mı?', a: 'Her cilt tipi nem dengesine ihtiyaç duyar; yağlı ciltler için hafif, su bazlı ve "oil-free" ifadeli formüller genellikle daha konforludur. Doku tercihi kişiseldir; cildinizde ağırlık hissi bırakmayan bir ürün seçmek pratik bir ölçüttür.' },
      { q: 'Hyalüronik asit ne işe yarar?', a: 'Hyalüronik asit, cilt bakımında sık kullanılan bir nem tutucu içeriktir ve formülün nemlendirici etkisine katkı sağlar. Etikette içerik olarak yer alması, ürünün nem odaklı olduğunu gösteren yaygın bir işarettir.' },
      { q: 'Gündüz ve gece nemlendiricisi farklı mı?', a: 'Bazı ürünler gündüz için hafif ve güneş koruyucuyla uyumlu, gece için daha zengin dokuda sunulur. Tek bir uygun nemlendiriciyi hem sabah hem akşam kullanmak da mümkündür; tercih rutininize ve cildinizin konforuna bağlıdır.' },
    ],
  },
  'cilt-temizleme-urunleri': {
    intro:
      'Cilt temizleme ürünleri; jel, köpük, temizleme yağı ve misel su gibi farklı formlarda sunulur ve makyaj ile gün içi kirini uzaklaştırmaya yardımcı olur. Seçerken cilt tipinizi ve kullanım anını (sabah/akşam) düşünün: yağlı ciltler jel/köpük dokuları, kuru ve hassas ciltler daha nazik, yağ bazlı veya misel formülleri tercih edebilir. Temizlik, çoğu bakım rutininin ilk adımıdır.',
    faq: [
      { q: 'Misel su mu jel temizleyici mi?', a: 'Misel su hızlı ve durulama gerektirmeyen bir temizlik sunar; jel/köpük temizleyiciler ise suyla durulanır ve yağlı ciltlerde ferahlık hissi verir. İkisini birlikte kullananlar da vardır. Seçim cilt tipine ve kullanım kolaylığına göre değişir.' },
      { q: 'Günde kaç kez yüz temizlenmeli?', a: 'Yaygın yaklaşım sabah ve akşam olmak üzere günde iki kez temizliktir; akşam temizliği makyaj ve gün içi kiri uzaklaştırmak açısından önemlidir. Çok sık veya sert temizlik cildi kurutabileceğinden, cildinizin konforunu gözetin.' },
      { q: 'Makyaj temizliği için ne kullanmalıyım?', a: 'Makyaj, özellikle suya dirençli ürünler, temizleme yağı veya misel su ile daha kolay uzaklaştırılır; ardından bir yüz temizleyiciyle ikinci temizlik yapmak yaygındır. Ürün etiketindeki kullanım önerisini dikkate alın.' },
    ],
  },
  'besin-takviyeleri': {
    intro:
      'Besin takviyeleri; vitamin, mineral ve benzeri besin ögelerini günlük alıma katkı amacıyla sunan takviye edici gıdalardır. Dengeli ve çeşitli beslenmenin yerini tutmaz. Ürün seçerken içeriği, günlük tüketim miktarını ve etiketteki kullanım/uyarıları okuyun. Hamilelik, emzirme, süregelen bir sağlık durumu veya düzenli ilaç kullanımı söz konusuysa kullanmadan önce hekiminize veya eczacınıza danışmanız önerilir. Takviye edici gıdalar ilaç değildir ve hastalıkların tedavisinde kullanılmaz.',
    faq: [
      { q: 'Besin takviyesi ilaç mıdır?', a: 'Hayır. Besin takviyeleri, günlük besin alımına katkı sağlamayı amaçlayan takviye edici gıdalardır; ilaç değildir ve hastalıkların teşhis veya tedavisinde kullanılmaz. Kullanım amacı ve miktarı için etiketi okuyun, gerektiğinde bir sağlık uzmanına danışın.' },
      { q: 'Takviye seçerken nelere bakmalıyım?', a: 'İçerik ve miktar, günlük önerilen tüketim, form (tablet, kapsül, damla, toz) ve etiketteki uyarılar önemli ölçütlerdir. İhtiyacınıza uygun olup olmadığını değerlendirmek için eczacınıza veya hekiminize danışabilirsiniz.' },
      { q: 'Takviyeleri ne zaman kullanmalıyım?', a: 'Kullanım zamanı ve şekli ürüne göre değişir ve etikette belirtilir (örneğin yemekle birlikte). Etiketteki önerilen günlük miktarı aşmayın; birden fazla takviye kullanıyorsanız bir sağlık uzmanına danışmanız önerilir.' },
    ],
  },
  'magnezyum-mineralleri': {
    intro:
      'Magnezyum takviyeleri, günlük magnezyum alımına katkı amacıyla sunulan takviye edici gıdalardır ve farklı formlarda bulunur (örneğin sitrat, bisglisinat, malat). Formlar; çözünürlük, mide konforu ve tablet/toz gibi kullanım biçimi bakımından farklılaşabilir. Seçerken içeriği, elementel magnezyum miktarını ve etiketteki günlük tüketim önerisini inceleyin. Takviye edici gıdalar ilaç değildir; hamilelik, emzirme veya süregelen sağlık durumlarında hekiminize danışın.',
    faq: [
      { q: 'Magnezyum bisglisinat ve sitrat arasındaki fark nedir?', a: 'Bunlar magnezyumun farklı bileşik formlarıdır ve çözünürlük ile mide konforu açısından kişiden kişiye farklı deneyimlenebilir. Hangi formun size uygun olduğu tercih ve tolerans meselesidir; kararsızsanız eczacınıza danışabilirsiniz. Her form etikette belirtilen günlük miktara göre kullanılır.' },
      { q: 'Magnezyum takviyesi nasıl kullanılır?', a: 'Kullanım miktarı ve zamanı ürünün formuna göre değişir ve etiketinde belirtilir. Önerilen günlük tüketim miktarını aşmayın. Düzenli ilaç kullanıyor veya bir sağlık durumunuz varsa kullanmadan önce hekiminize danışmanız önerilir.' },
      { q: 'Toz mu tablet mi tercih etmeliyim?', a: 'Toz formlar suya karıştırılarak kullanım ve miktar ayarı kolaylığı sunarken, tablet/kapsül taşınabilirlik açısından pratiktir. Seçim tamamen kullanım alışkanlığına bağlıdır; içerik ve miktar için etiketi karşılaştırabilirsiniz.' },
    ],
  },
  'omega-yag-asitleri': {
    intro:
      'Omega yağ asidi takviyeleri (örneğin EPA ve DHA içeren balık yağı veya bitkisel kaynaklı ürünler), günlük alıma katkı amacıyla sunulan takviye edici gıdalardır. Seçerken kaynağı (balık, alg, bitkisel), porsiyon başına EPA/DHA miktarını, formu (kapsül, sıvı) ve etiketteki kullanım önerisini değerlendirin. Takviye edici gıdalar ilaç değildir ve dengeli beslenmenin yerine geçmez; gerektiğinde bir sağlık uzmanına danışın.',
    faq: [
      { q: 'EPA ve DHA nedir?', a: 'EPA ve DHA, omega-3 yağ asitlerinin sık karşılaşılan iki türüdür ve genellikle balık yağı veya alg kaynaklı takviyelerde bulunur. Porsiyon başına miktarları ürün etiketinde belirtilir; ihtiyacınıza uygun ürünü seçerken bu değerleri karşılaştırabilirsiniz.' },
      { q: 'Balık yağı mı bitkisel omega mı?', a: 'Balık yağı yaygın bir kaynaktır; alg bazlı ve bitkisel seçenekler ise balık tüketmeyenler için alternatif sunar. Tercih beslenme alışkanlığınıza ve etiketteki içerik/miktara göre değişir. Kararsızsanız eczacınıza danışabilirsiniz.' },
      { q: 'Omega takviyesi nasıl saklanır?', a: 'Saklama koşulları ürüne göre değişir ve etikette belirtilir; birçok sıvı ürün açıldıktan sonra serin yerde veya buzdolabında saklanır. Son kullanma tarihine ve etiketteki saklama yönergesine uymanız önerilir.' },
    ],
  },
  'd3-k2-vitamini': {
    intro:
      'D3 ve K2 vitamini içeren takviyeler, günlük vitamin alımına katkı amacıyla birlikte sunulan takviye edici gıdalardır ve damla, tablet veya sprey gibi formlarda bulunur. Seçerken porsiyon başına D3 ve K2 miktarını, formu ve etiketteki kullanım önerisini inceleyin. Vitamin D düzeyiyle ilgili bir değerlendirme veya doz ihtiyacı için hekiminize danışmanız önerilir. Takviye edici gıdalar ilaç değildir.',
    faq: [
      { q: 'D3 ve K2 neden birlikte sunulur?', a: 'Bu iki vitamin, takviye ürünlerinde sık sık aynı üründe bir arada sunulur. Hangi ürünün size uygun olduğu içerikteki miktarlara ve ihtiyacınıza bağlıdır; doz konusunda kararsızsanız bir sağlık uzmanına danışmanız önerilir.' },
      { q: 'Damla mı tablet mi seçmeliyim?', a: 'Damla ve sprey formlar miktar ayarı ve kullanım kolaylığı sunarken, tablet/kapsül taşınabilirlik açısından pratiktir. Seçim kullanım alışkanlığına göre değişir; porsiyon başına vitamin miktarını etiketten karşılaştırabilirsiniz.' },
      { q: 'D vitamini takviyesi almadan önce ne yapmalıyım?', a: 'Vitamin D ihtiyacı kişiden kişiye değişebildiğinden, düzenli takviye kullanımından önce hekiminize danışmanız ve etiketteki önerilen günlük miktarı aşmamanız önerilir. Takviye edici gıdalar hastalık tedavisi amacıyla kullanılmaz.' },
    ],
  },
  'sac-bakimi': {
    intro:
      'Saç bakımı ürünleri; şampuan, saç kremi, maske ve serum gibi seçeneklerle saç ve saç derisinin bakımına yardımcı olur. Doğru ürünü seçmenin yolu saç tipinizi (kuru, yağlı, boyalı, kıvırcık) ve saç derisi ihtiyacınızı tanımaktan geçer. İçerik listesini okumak ve saç tipinize uygun formülleri tercih etmek pratik bir yaklaşımdır. Kullanım sıklığı ürüne ve saç yapısına göre değişir.',
    faq: [
      { q: 'Saç tipime uygun şampuanı nasıl seçerim?', a: 'Yağlı saçlar ferahlatıcı/dengeleyici, kuru saçlar nemlendirici, boyalı saçlar ise renk koruyucu ("color-safe") formülleri tercih edebilir. Etiketteki saç tipi önerisi ve içerik, seçim için pratik bir rehberdir. Farklı ürünleri deneyerek saçınıza uyanı bulabilirsiniz.' },
      { q: 'Saç kremi ve maske farkı nedir?', a: 'Saç kremi genellikle her yıkamada kullanılan, tarama kolaylığı sağlayan bir üründür; maske ise daha yoğun bakım için belirli aralıklarla uygulanır. İkisini birlikte kullananlar da vardır; kullanım sıklığı etikette belirtilir.' },
      { q: 'Şampuanı ne sıklıkta kullanmalıyım?', a: 'Yıkama sıklığı saç tipine ve kişisel tercihe göre değişir; yağlı saçlar daha sık, kuru saçlar daha seyrek yıkanabilir. Cildinizi ve saçınızı yormayacak bir denge bulmak yaygın bir öneridir.' },
    ],
  },
  'makyaj': {
    intro:
      'Makyaj ürünleri; ten (fondöten, kapatıcı, pudra), göz (maskara, far, eyeliner) ve dudak (ruj, parlatıcı) kategorilerinde geniş bir yelpaze sunar. Ten ürünlerinde doğru tonu seçmek, göz ve dudakta ise kalıcılık ile doku tercihi öne çıkar. Cilt tipinize uygun doku (mat, ışıltılı, nemlendiricili) seçmek konforu artırır. Gün sonunda makyajı uygun bir temizleyiciyle çıkarmak cilt bakımının bir parçasıdır.',
    faq: [
      { q: 'Fondöten tonumu nasıl seçerim?', a: 'Ten tonunuza en yakın rengi çene veya boyun hattında denemek yaygın bir yöntemdir; doğal ışıkta bakmak seçim kolaylaştırır. Cilt alt tonunuza (sıcak, soğuk, nötr) uygun ton daha doğal bir görünüm verir. Doku tercihiniz cilt tipinize göre değişebilir.' },
      { q: 'Yağlı ciltler için hangi ürünler uygun?', a: 'Yağlı ciltler genellikle mat bitişli, uzun süre kalıcı ("long-lasting") ve "oil-free" ifadeli ürünlerle daha konforlu sonuç alır. Pudra ile sabitleme de gün içi ferahlık sağlayabilir. Tercih kişisel beklentiye göre değişir.' },
      { q: 'Makyaj nasıl çıkarılmalı?', a: 'Makyaj, temizleme yağı veya misel su ile yumuşatılarak çıkarılır; ardından bir yüz temizleyiciyle ikinci temizlik yaygın bir uygulamadır. Göz makyajı için göz çevresine uygun ürünler tercih edilir.' },
    ],
  },
  'anne-ve-bebek': {
    intro:
      'Anne ve bebek bakım ürünleri; bebeklerin hassas cildi ve annelerin bakım ihtiyaçları düşünülerek geliştirilen şampuan, losyon, pişik kremi ve temizlik ürünlerini kapsar. Bebek ürünlerinde koku ve boya bakımından sade içerik, dermatolojik test bilgisi ve yaş uygunluğu öne çıkan ölçütlerdir. Etiketteki kullanım ve yaş yönergelerini izlemek, yeni bir ürünü kademeli denemek pratik bir yaklaşımdır.',
    faq: [
      { q: 'Bebek ürünü seçerken nelere dikkat etmeliyim?', a: 'Koku ve boya açısından sade içerik, dermatolojik olarak test edilmiş olması ve yaş uygunluğu sık aranan ölçütlerdir. Ürünün hangi yaştan itibaren uygun olduğu etiketinde belirtilir. Kararsız kaldığınızda bir sağlık uzmanına danışabilirsiniz.' },
      { q: 'Bebek şampuanı yetişkininkinden farklı mı?', a: 'Bebeklere yönelik ürünler genellikle daha nazik, göz yakmayan ("no tears") ve sade içerikli formüllerle sunulur. Temel amaç aynı olsa da formülasyon hassas cilde uygun olacak şekilde hazırlanır. Kullanım için etiket yönergesini izleyin.' },
      { q: 'Yeni bir bebek ürününü nasıl denemeliyim?', a: 'Yeni ürünü kademeli kullanmaya başlamak ve cildin tepkisini gözlemlemek yaygın bir yaklaşımdır. Herhangi bir tahriş görülürse kullanımı bırakıp bir sağlık uzmanına danışmanız önerilir. Etiketteki uyarılar önceliklidir.' },
    ],
  },
};
