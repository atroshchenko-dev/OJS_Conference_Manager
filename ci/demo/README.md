# Демо-сайт / Demo site

`ci/demo/start-demo.sh` запускає в Docker чистий OJS 3.5 з плагіном і шістьма журналами-прикладами. Кожен журнал відповідає типовому закладу з матриці конфігурацій (`tests/fixtures/segments/`) і налаштований майстром першого налаштування з відповідним типовим набором. Прийом заявок у всіх виданнях відкрито, тож публічні сторінки й «Майбутні заходи» одразу мають що показати.

| Журнал | Заклад | Типовий набір |
|---|---|---|
| `s1` | приватний ЗВО | `ua-university-private` |
| `s2` | державний ЗВО | `ua-university-state` |
| `s3` | ІППО | `ua-ippo` |
| `s4` | наукова установа з ГО | `ua-research-institute` |
| `s5` | учнівська конференція | `ua-man` |
| `sgen` | організатор з-за меж України | `generic-conference` |

Вхід: `admin` / `admin12345`. Усі дані вигадані. Не запускайте скрипт на робочому сервері закладу: він видаляє контейнери `ojs` і `ojsdb` і створює їх заново.

Щоб демо щоночі поверталося до початкового стану, додайте скрипт у cron сервера демо:

```
15 3 * * *  cd /opt/conferenceManager && ci/demo/start-demo.sh >> /var/log/cm-demo.log 2>&1
```

---

`ci/demo/start-demo.sh` starts a clean OJS 3.5 in Docker with the plugin and six example journals, one per typical institution of the configuration matrix, each set up by the first setup wizard with an open call. Sign in as `admin` / `admin12345`. All data is made up. Never run it on a production server: it removes and recreates the `ojs` and `ojsdb` containers. Add it to cron (see above) for a nightly reset.
