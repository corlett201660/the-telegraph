document.addEventListener('DOMContentLoaded', function() {
    
    // Safety check: ensure localization data loaded
    if (typeof qrsgData === 'undefined') {
        console.error("QR Story Generator: Localization data missing.");
        return;
    }

    let voicesLoaded = false;
    function loadVoices() {
        if (window.speechSynthesis) {
            const voices = window.speechSynthesis.getVoices();
            if (voices.length > 0) voicesLoaded = true;
        }
    }
    if (window.speechSynthesis) {
        loadVoices();
        if ('onvoiceschanged' in window.speechSynthesis) {
            window.speechSynthesis.onvoiceschanged = loadVoices;
        }
    }

    const readerModalEl = document.getElementById('qrsg-reader-modal');
    let readerModal = null;
    if (typeof bootstrap !== 'undefined') {
        readerModal = new bootstrap.Modal(readerModalEl);
    } else {
        console.error("Bootstrap is missing. Modals will not open. Check your plugin root directory.");
    }

    const appContainer = document.getElementById('app-container');
    const defaultBlueprintUrl = qrsgData.default_blueprint_url || '';
    
    const video = document.getElementById('qr-video');
    const canvasElement = document.getElementById('qr-canvas');
    const canvas = canvasElement.getContext('2d');
    const videoContainer = document.getElementById('video-container');
    const startScanBtn = document.getElementById('start-scan-btn');
    const scanAnotherBtn = document.getElementById('scan-another-btn');
    
    const resultContainer = document.getElementById('result-container');
    const resultTitle = document.getElementById('result-title');
    const resultInstructions = document.getElementById('result-instructions');
    const loadingIndicator = document.getElementById('loading-indicator');
    const postGenerationActions = document.getElementById('post-generation-actions');
    
    const storyResultArea = document.getElementById('story-result-area');
    const storyTtsControls = document.getElementById('story-tts-controls');
    const storyTtsPlayPauseBtn = document.getElementById('story-tts-play-pause-btn');
    const storyTtsStopBtn = document.getElementById('story-tts-stop-btn');
    const storyTtsRewindBtn = document.getElementById('story-tts-rewind-btn');
    const storyTtsRateSelect = document.getElementById('story-tts-rate-select');
    const storyTtsSettings = document.getElementById('story-tts-settings');
    
    const plantSeedBtn = document.getElementById('plant-seed-btn');
    const finalSubmitBtn = document.getElementById('final-submit-btn');
    const storyInputArea = document.getElementById('story-input-area');
    const userPromptInput = document.getElementById('user-prompt');
    const continueStoryBtn = document.getElementById('continue-story-btn');
    const getSuggestionsBtn = document.getElementById('get-suggestions-btn');
    const suggestionsModalBody = document.getElementById('suggestions-modal-body');
    const qrcodeContainer = document.getElementById('qrcode-container');
    const topWordsDisplay = document.getElementById('top-words-display');
    const actionButtonsArea = document.getElementById('action-buttons-area');
    const includeHistoryToggle = document.getElementById('include-history-toggle');
    const storyTtsLoopBtn = document.getElementById('story-tts-loop-btn');
    const restartOnContinuationToggle = document.getElementById('restart-on-continuation-toggle');
    
    let stream = null; 
    let isProcessing = false; 
    let storyHistory = []; 
    let lastGeneratedStoryContent = ''; 
    let lastGeneratedKeywords = []; 
    let lastGeneratedStoryTitle = ''; 
    let lastGeneratedSuggestions = null;
    let lastGeneratedRedirectUrl = ''; 
    let currentBlueprintBaseUrl = '';
    
    let storyUtteranceQueue = [];
    let storySentenceSpans = [];
    let currentUtteranceIndex = 0;
    let currentTtsRate = 1;
    let isLooping = false;
    let storyTextBeforeContinuation = '';

    const stopWords = new Set(['the','be','to','of','and','a','in','that','have','i','it','for','not','on','with','he','as','you','do','at','this','but','his','by','from','they','we','say','her','she','or','an','will','my','one','all','would','there','their','what','so','up','out','if','about','who','get','which','go','me','when','make','can','like','time','no','just','him','know','take','person','into','year','your','good','some','could','them','see','other','than','then','now','look','only','come','its','over','think','also','back','after','use','two','how','our','work','first','well','way','even','new','want','because','any','these','give','day','most','us']);
    const futharkMap={'a':'ᚨ','b':'ᛒ','c':'ᚲ','d':'ᛞ','e':'ᛖ','f':'ᚠ','g':'ᚷ','h':'ᚺ','i':'ᛁ','j':'ᛃ','k':'ᚲ','l':'ᛚ','m':'ᛗ','n':'ᚾ','o':'ᛟ','p':'ᛈ','q':'ᚲ','r':'ᚱ','s':'ᛊ','t':'ᛏ','u':'ᚢ','v':'ᚹ','w':'ᚹ','x':'ᛉ','y':'ᛁ','z':'ᛉ','ng':'ᛜ','th':'ᚦ'};
    
    function transliterateToFuthark(word){
        let lowerWord=word.toLowerCase();
        let runes='';
        let i=0;
        while(i<lowerWord.length){
            if(i+1<lowerWord.length){
                let twoChar=lowerWord.substring(i,i+2);
                if(futharkMap[twoChar]){
                    runes+=futharkMap[twoChar];
                    i+=2;
                    continue;
                }
            }
            let oneChar=lowerWord[i];
            runes+=futharkMap[oneChar]||oneChar;
            i+=1;
        }
        return runes;
    }

    async function generateStory(qrData, isContinuation = false, userPrompt = '') {
        storyTextBeforeContinuation = isContinuation ? lastGeneratedStoryContent : '';
        
        if (!isContinuation) {
            loadingIndicator.innerHTML = '<div class="loader"></div>';
            postGenerationActions.classList.add('hidden');
        } else {
            storyResultArea.innerHTML += '<div id="temp-loader" class="loader my-4"></div>';
        }
        
        storyInputArea.classList.add('hidden');
        actionButtonsArea.classList.add('hidden');
        plantSeedBtn.disabled = true;
        document.getElementById('submit-story-text-btn').disabled = true;
        getSuggestionsBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'qrsg_generate_content');
        formData.append('nonce', qrsgData.nonce); // Updated to use Localized object
        
        if (isContinuation) {
            formData.append('request_type', 'continuation');
            formData.append('story_text', lastGeneratedStoryContent);
            formData.append('user_prompt', userPrompt);
        } else {
            isProcessing = true;
            formData.append('request_type', 'story');
            formData.append('qr_data', qrData);
        }

        try {
            const response = await fetch(qrsgData.ajax_url, { method: 'POST', body: formData }); // Updated
            const result = await response.json();
            
            if (!result.success) throw new Error(result.data || 'The API returned an error.');

            let storyText = result.data.story;

            if (isContinuation) {
                let cleanOld = storyTextBeforeContinuation.trim();
                let snippet = cleanOld.substring(0, 40);
                if (snippet.length > 10 && storyText.trim().indexOf(snippet) !== -1) {
                    let endSnippet = cleanOld.substring(cleanOld.length - 40);
                    let endIndex = storyText.lastIndexOf(endSnippet);
                    if (endIndex !== -1) {
                        storyText = storyText.substring(endIndex + endSnippet.length).trim();
                    }
                }
            }
            
            storyText = storyText.trim();
            if (!isContinuation) lastGeneratedRedirectUrl = result.data.redirect_url || '';

            lastGeneratedStoryContent = isContinuation ? lastGeneratedStoryContent + "\n\n" + storyText : storyText;
            
            lastGeneratedKeywords = getTop10Words(lastGeneratedStoryContent);
            lastGeneratedStoryTitle = (lastGeneratedKeywords.length > 0 ? lastGeneratedKeywords.slice(0, 3).map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ') : 'Generated') + ' Story';

            if(isContinuation) {
                storyHistory.push({ type: 'continuation', prompt: userPrompt, result: storyText });
            } else {
                storyHistory = [{ type: 'initial', prompt: qrData, result: storyText }];
            }

            initializeStoryTTS(lastGeneratedStoryContent, isContinuation);
            
            loadingIndicator.innerHTML = '';
            postGenerationActions.classList.remove('hidden');
            actionButtonsArea.classList.remove('hidden');
            if (lastGeneratedRedirectUrl || defaultBlueprintUrl) plantSeedBtn.disabled = false;
            document.getElementById('submit-story-text-btn').disabled = false;
            storyInputArea.classList.remove('hidden');
            continueStoryBtn.disabled = false;
            userPromptInput.disabled = false;
            userPromptInput.value = '';
            lastGeneratedSuggestions = null;
            getSuggestionsBtn.disabled = false;

            if (!isContinuation && readerModal) {
                readerModal.show();
            }

        } catch (error) {
            loadingIndicator.innerHTML = `
                <div class="alert alert-warning mt-3">
                    <p class="mb-2"><strong>Oops! The request failed.</strong></p>
                    <p class="small text-muted mb-2">${error.message}</p>
                    <button id="retry-story-btn" class="btn btn-warning btn-sm">Retry</button>
                </div>`;
            
            document.getElementById('retry-story-btn').addEventListener('click', () => {
                generateStory(qrData, isContinuation, userPrompt);
            });
            
            if(isContinuation) {
                const tempLoader = document.getElementById('temp-loader');
                if (tempLoader) tempLoader.remove();
                storyInputArea.classList.remove('hidden');
                continueStoryBtn.disabled = false;
                userPromptInput.disabled = false;
            }
        } finally {
            if (!isContinuation) isProcessing = false;
        }
    }
    
    function initializeStoryTTS(fullText, isContinuation = false) {
        handleStoryTtsStop();
        
        let textToProcess = fullText;
        let startIndex = 0;

        if (isContinuation && !restartOnContinuationToggle.checked) {
            textToProcess = fullText.substring(storyTextBeforeContinuation.length);
            startIndex = storyUtteranceQueue.length;
            const tempLoader = document.getElementById('temp-loader');
            if (tempLoader) tempLoader.remove();
        } else {
            storySentenceSpans = [];
            storyUtteranceQueue = [];
            currentUtteranceIndex = 0;
            storyResultArea.innerHTML = '';
        }

        let ttsCapable = false;
        if (window.speechSynthesis) {
            const availableVoices = window.speechSynthesis.getVoices();
            if (availableVoices.length > 0 || voicesLoaded) {
                ttsCapable = true;
            }
        }

        if (!ttsCapable) {
            storyTtsControls.classList.add('hidden');
            storyTtsSettings.classList.add('hidden');
        }

        if (startIndex === 0) {
             const sentencesForRedraw = fullText.match(/[^.!?\n]+[.!?\n]*|\n+/g) || [];
             sentencesForRedraw.forEach(sentenceText => {
                const span = document.createElement('span');
                span.textContent = sentenceText;
                span.className = 'story-sentence';
                storySentenceSpans.push(span);
                storyResultArea.appendChild(span);
            });
        }
        
        const newSentences = textToProcess.match(/[^.!?\n]+[.!?\n]*|\n+/g) || [];
        if (newSentences.length === 0 && startIndex === 0) return;

        newSentences.forEach((sentenceText) => {
            if (startIndex > 0) {
                const span = document.createElement('span');
                span.textContent = sentenceText;
                span.className = 'story-sentence';
                storySentenceSpans.push(span);
                storyResultArea.appendChild(span);
            }
            
            if (/\w/.test(sentenceText) && ttsCapable) { 
                const utterance = new SpeechSynthesisUtterance(sentenceText);
                utterance.rate = currentTtsRate;
                
                const utteranceIndex = storyUtteranceQueue.length; 
                
                utterance.onstart = () => {
                    currentUtteranceIndex = utteranceIndex;
                    storySentenceSpans[utteranceIndex].classList.add('highlighted-sentence');
                    storySentenceSpans[utteranceIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
                };
                utterance.onend = () => {
                    storySentenceSpans[utteranceIndex].classList.remove('highlighted-sentence');
                };
                storyUtteranceQueue.push(utterance);
            }
        });

        if (storyUtteranceQueue.length > 0 && ttsCapable) {
            const lastUtterance = storyUtteranceQueue[storyUtteranceQueue.length - 1];
            lastUtterance.addEventListener('end', () => {
                if (isLooping) {
                    setTimeout(() => {
                        handleStoryTtsStop();
                        handleStoryTtsPlayPause();
                    }, 500); 
                } else {
                    storyTtsPlayPauseBtn.innerHTML = '▶️';
                    currentUtteranceIndex = 0;
                }
            });
            
            storyTtsControls.classList.remove('hidden');
            storyTtsSettings.classList.remove('hidden');
            storyTtsPlayPauseBtn.innerHTML = '▶️';
        }
        
        if (isContinuation && !restartOnContinuationToggle.checked && ttsCapable) {
            handleStoryTtsPlayPause(startIndex);
        }
    }

    function handleStoryTtsPlayPause(startIndex = 0) {
        if (!window.speechSynthesis) return;
        if (storyUtteranceQueue.length === 0) return;

        if (window.speechSynthesis.speaking && !window.speechSynthesis.paused) {
            window.speechSynthesis.pause();
            storyTtsPlayPauseBtn.innerHTML = '▶️';
        } else if (window.speechSynthesis.paused) {
            window.speechSynthesis.resume();
            storyTtsPlayPauseBtn.innerHTML = '⏸️';
        } else {
            const playFrom = Math.max(currentUtteranceIndex, startIndex);
            for (let i = playFrom; i < storyUtteranceQueue.length; i++) {
                window.speechSynthesis.speak(storyUtteranceQueue[i]);
            }
            storyTtsPlayPauseBtn.innerHTML = '⏸️';
        }
    }
    
    async function handleSubmitPost(title, content, keywords) { 
        let submitModal = null; 
        if (typeof bootstrap !== 'undefined') { 
            submitModal = bootstrap.Modal.getInstance(document.getElementById('qrsg-submit-modal')); 
        } 
        const submitBtn = document.getElementById('final-submit-btn'); 
        if (!submitBtn) return; 
        
        const originalText = submitBtn.textContent; 
        submitBtn.disabled = true; 
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...'; 
        
        let finalContent = ""; 
        if(includeHistoryToggle.checked) { 
            finalContent = '<h3>Full Story History</h3>'; 
            storyHistory.forEach((entry, index) => { 
                finalContent += `<h4>Part ${index + 1}</h4>`; 
                finalContent += `<p><strong>Prompt (${entry.type}):</strong><br><em>${entry.prompt.replace(/\n/g, '<br>')}</em></p>`; 
                finalContent += `<p><strong>Result:</strong></p><div>${entry.result.replace(/\n/g, '<br>')}</div><hr>`; 
            }); 
            finalContent += '<h3>Final Compiled Story</h3>'; 
        } 
        finalContent += `<p>${content.replace(/\n/g, '<br>')}</p>`; 
        
        const formData = new FormData(); 
        formData.append('action', 'qrsg_submit_story'); 
        formData.append('nonce', qrsgData.nonce); // Updated
        formData.append('title', title); 
        formData.append('content', finalContent); 
        formData.append('keywords', keywords.join(',')); 
        
        try { 
            const response = await fetch(qrsgData.ajax_url, { method: 'POST', body: formData }); // Updated
            const result = await response.json(); 
            if (!result.success) throw new Error(result.data || 'Unknown error.'); 
            
            submitBtn.textContent = 'Submission Successful!'; 
            submitBtn.classList.remove('btn-primary'); 
            submitBtn.classList.add('btn-success'); 
        } catch (error) { 
            submitBtn.textContent = 'Submission Failed!'; 
            submitBtn.classList.remove('btn-primary'); 
            submitBtn.classList.add('btn-danger'); 
            alert(`Submission Failed: ${error.message}`); 
        } finally { 
            setTimeout(() => { 
                if (submitModal) submitModal.hide(); 
                submitBtn.textContent = originalText; 
                submitBtn.classList.remove('btn-success', 'btn-danger'); 
                submitBtn.classList.add('btn-primary'); 
                submitBtn.disabled = false; 
            }, 2000); 
        } 
    }
    
    function resetApp() { 
        if (isProcessing) return; 
        isProcessing = false; 
        handleStoryTtsStop(); 
        stopScan(); 
        resultContainer.classList.add('hidden'); 
        startScanBtn.classList.remove('hidden'); 
        storyResultArea.innerHTML = ''; 
        storyTtsControls.classList.add('hidden'); 
        storyTtsSettings.classList.add('hidden'); 
        storyInputArea.classList.add('hidden'); 
        actionButtonsArea.classList.add('hidden'); 
        postGenerationActions.classList.add('hidden');
        getSuggestionsBtn.disabled = true; 
        lastGeneratedStoryContent = ''; 
        lastGeneratedSuggestions = null; 
        storyHistory = []; 
        lastGeneratedRedirectUrl = ''; 
        currentBlueprintBaseUrl = ''; 
    }
    
    function handlePlantSeed(textToAnalyze) { 
        if (!textToAnalyze) return; 
        currentBlueprintBaseUrl = lastGeneratedRedirectUrl || defaultBlueprintUrl; 
        
        if (!currentBlueprintBaseUrl) { 
            alert("Cannot generate QR Code: No default or specific blueprint is configured in the plugin settings."); 
            return; 
        } 
        
        if (typeof QRCode === 'undefined') { 
            alert("QRCode library is missing. Make sure it is properly installed in the plugin root."); 
            return; 
        } 
        
        const topWords = getTop10Words(textToAnalyze); 
        lastGeneratedKeywords = topWords; 
        topWordsDisplay.textContent = topWords.join(' '); 
        const encodedKeywords = topWords.map(w => encodeURIComponent(w)).join(','); 
        const separator = currentBlueprintBaseUrl.includes('?') ? '&' : '?'; 
        const qrCodeText = `${currentBlueprintBaseUrl}${separator}keywords=${encodedKeywords}`; 
        
        qrcodeContainer.innerHTML = ""; 
        new QRCode(qrcodeContainer, { 
            text: qrCodeText, 
            width: 200, 
            height: 200, 
            colorDark: "#000000", 
            colorLight: "#ffffff", 
            correctLevel: QRCode.CorrectLevel.H 
        }); 
    }
    
    function handleBlueprintLink(event) { 
        event.preventDefault(); 
        if (!currentBlueprintBaseUrl) { alert("Cannot launch blueprint. Base URL not found."); return; } 
        if (typeof QRCode === 'undefined') return; 
        
        const format = event.target.dataset.format; 
        const topWords = lastGeneratedKeywords; 
        if (topWords.length === 0) return; 
        
        let keywordsString; 
        switch (format) { 
            case 'runes': keywordsString = topWords.map(word => encodeURIComponent(transliterateToFuthark(word))).join(','); break; 
            case 'combined': keywordsString = topWords.map(word => `${encodeURIComponent(word)}:${encodeURIComponent(transliterateToFuthark(word))}`).join(','); break; 
            default: keywordsString = topWords.map(word => encodeURIComponent(word)).join(','); break; 
        } 
        
        const separator = currentBlueprintBaseUrl.includes('?') ? '&' : '?'; 
        const url = `${currentBlueprintBaseUrl}${separator}keywords=${keywordsString}`; 
        
        qrcodeContainer.innerHTML = ''; 
        new QRCode(qrcodeContainer, { 
            text: url, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H 
        }); 
        
        window.open(url, '_blank'); 
    }
    
    async function handleStartScanClick() { 
        if (isProcessing) return; 

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert("Camera access failed! Your browser blocked the camera. Ensure you are loading this page over HTTPS (or localhost) and that your browser supports media devices.");
            return;
        }

        startScanBtn.disabled = true; 
        startScanBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Starting Camera...`; 
        try { 
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: "environment" } } }); 
        } catch (err) { 
            try { 
                stream = await navigator.mediaDevices.getUserMedia({ video: true }); 
            } catch (fallbackErr) { 
                document.getElementById('app-instructions').textContent = "Camera permission denied or no camera found. Please grant access in your browser's site settings and try again."; 
                startScanBtn.disabled = false; 
                startScanBtn.innerHTML = 'Start Scan'; 
                return; 
            } 
        } 
        video.srcObject = stream; 
        video.muted = true; 
        video.setAttribute('playsinline', true); 
        try { 
            await video.play(); 
            videoContainer.classList.remove('hidden'); 
            startScanBtn.classList.add('hidden'); 
            document.getElementById('app-instructions').textContent = 'Point the camera at a QR Code...'; 
            requestAnimationFrame(tick); 
        } catch (playErr) { 
            alert("Camera was found, but could not be played. Please check browser policies."); 
            stopScan(); 
        } 
    }

    function stopScan() { 
        if (stream) { 
            stream.getTracks().forEach(track => track.stop()); 
            stream = null; 
        } 
        videoContainer.classList.add('hidden'); 
        startScanBtn.classList.remove('hidden'); 
        startScanBtn.disabled = false; 
        startScanBtn.innerHTML = 'Start Scan'; 
        document.getElementById('app-instructions').textContent = 'Click "Start Scan" to activate your camera.';
    }
    
    function tick() { 
        if (isProcessing) return; 
        
        if (typeof jsQR === 'undefined') {
            alert("The jsQR scanning library failed to load. Check your plugin root directory.");
            stopScan();
            return;
        }

        if (video.readyState === video.HAVE_ENOUGH_DATA) { 
            canvasElement.height = video.videoHeight; 
            canvasElement.width = video.videoWidth; 
            canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height); 
            const imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height); 
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' }); 
            if (code && code.data.trim()) { 
                isProcessing = true; 
                if (navigator.vibrate) { navigator.vibrate(200); } 
                stopScan(); 
                document.getElementById('app-instructions').textContent = 'QR Code detected! Processing...'; 
                handleQRCodeData(code.data); 
            } 
        } 
        if (!isProcessing) { requestAnimationFrame(tick); } 
    }

    function handleQRCodeData(qrData) { 
        document.getElementById('app-instructions').classList.add('hidden'); 
        resultContainer.classList.remove('hidden'); 
        startScanBtn.classList.add('hidden'); 
        resultTitle.textContent = "Story Generated"; 
        resultInstructions.textContent = `Based on: "${qrData}"`; 
        generateStory(qrData); 
    }
    
    function handleContinueStory() { 
        const userPrompt = userPromptInput.value.trim(); 
        if (!lastGeneratedStoryContent || !userPrompt) { 
            return alert('Please write something to continue the story.'); 
        } 
        generateStory(null, true, userPrompt); 
    }
    
    async function generateSuggestions() { 
        const formData = new FormData(); 
        formData.append('action', 'qrsg_generate_content'); 
        formData.append('nonce', qrsgData.nonce); // Updated
        formData.append('request_type', 'suggestion'); 
        formData.append('story_text', lastGeneratedStoryContent); 
        try { 
            const response = await fetch(qrsgData.ajax_url, { method: 'POST', body: formData }); // Updated
            const result = await response.json(); 
            if (!result.success) throw new Error(result.data); 
            let suggestionsText = result.data.story; 
            const jsonMatch = suggestionsText.match(/\{[\s\S]*\}/); 
            if (!jsonMatch) throw new Error("AI response was not valid JSON."); 
            lastGeneratedSuggestions = JSON.parse(jsonMatch[0]); 
        } catch (error) { 
            console.error('Suggestion fetch failed:', error); 
            lastGeneratedSuggestions = { error: `Failed to load suggestions: ${error.message}` }; 
        } 
    }
    
    function displaySuggestionsInModal() { 
        suggestionsModalBody.innerHTML = ''; 
        const list = document.createElement('ul'); 
        list.className = 'list-unstyled mb-0'; 
        if (lastGeneratedSuggestions.error) { 
            list.innerHTML = `<li class="text-danger">${lastGeneratedSuggestions.error}</li>`; 
        } else { 
            const items = [lastGeneratedSuggestions.question1, lastGeneratedSuggestions.question2, lastGeneratedSuggestions.statement]; 
            items.forEach(text => { 
                if (text) { 
                    const li = document.createElement('li'); 
                    li.className = 'py-3 d-flex justify-content-between align-items-center'; 
                    li.innerHTML = `<span class="me-3">${text}</span><button class="btn btn-sm btn-outline-primary use-suggestion-btn flex-shrink-0 rounded-circle" style="width:32px;height:32px;" title="Use" data-bs-dismiss="modal"><i class="fas fa-plus" style="pointer-events:none;"></i></button>`; 
                    li.querySelector('.use-suggestion-btn').dataset.suggestion = text; 
                    list.appendChild(li); 
                } 
            }); 
        } 
        suggestionsModalBody.appendChild(list); 
    }
    
    function getTop10Words(text) { 
        const words = text.toLowerCase().match(/\b\w+\b/g) || []; 
        const wordCounts = {}; 
        words.forEach(word => { 
            if (word.length > 2 && !stopWords.has(word)) { 
                wordCounts[word] = (wordCounts[word] || 0) + 1; 
            } 
        }); 
        return Object.keys(wordCounts).sort((a, b) => wordCounts[b] - wordCounts[a]).slice(0, 10); 
    }

    function handleStoryTtsStop() { 
        if (!window.speechSynthesis) return; 
        
        if (window.speechSynthesis.speaking || window.speechSynthesis.paused) { 
            const currentSpan = storySentenceSpans[currentUtteranceIndex]; 
            if (currentSpan) { 
                currentSpan.classList.remove('highlighted-sentence'); 
            } 
            window.speechSynthesis.cancel(); 
        } 
        storyTtsPlayPauseBtn.innerHTML = '▶️'; 
        currentUtteranceIndex = 0; 
    }

    // ########### EVENT LISTENERS ###########
    startScanBtn.addEventListener('click', handleStartScanClick);
    scanAnotherBtn.addEventListener('click', resetApp);
    continueStoryBtn.addEventListener('click', handleContinueStory);
    finalSubmitBtn.addEventListener('click', () => handleSubmitPost(lastGeneratedStoryTitle, lastGeneratedStoryContent, lastGeneratedKeywords));
    plantSeedBtn.addEventListener('click', () => handlePlantSeed(lastGeneratedStoryContent));
    storyTtsPlayPauseBtn.addEventListener('click', () => handleStoryTtsPlayPause());
    storyTtsStopBtn.addEventListener('click', handleStoryTtsStop);
    storyTtsRewindBtn.addEventListener('click', () => { handleStoryTtsStop(); setTimeout(() => handleStoryTtsPlayPause(), 100); });
    
    storyTtsLoopBtn.addEventListener('click', () => {
        isLooping = !isLooping;
        storyTtsLoopBtn.style.color = isLooping ? 'var(--qrsg-primary)' : 'inherit';
        storyTtsLoopBtn.style.backgroundColor = isLooping ? 'rgba(0, 242, 255, 0.2)' : '';
    });
    
    storyTtsRateSelect.addEventListener('change', (event) => { 
        currentTtsRate = parseFloat(event.target.value); 
        storyUtteranceQueue.forEach(utterance => utterance.rate = currentTtsRate); 
        if (window.speechSynthesis && (window.speechSynthesis.speaking || window.speechSynthesis.paused)) { 
            handleStoryTtsStop(); 
            setTimeout(() => handleStoryTtsPlayPause(), 100); 
        } 
    });
    
    document.querySelectorAll('#quick-actions-menu .quick-action-item').forEach(item => {
        item.addEventListener('click', (event) => {
            event.preventDefault();
            userPromptInput.value = item.textContent;
            handleContinueStory();
        });
    });
    
    readerModalEl.addEventListener('hidden.bs.modal', function () {
        handleStoryTtsStop();
    });
    
    document.getElementById('qrsg-suggestions-modal').addEventListener('show.bs.modal', async function() {
        if (!lastGeneratedSuggestions) {
            suggestionsModalBody.innerHTML = '<div class="loader"></div>';
            await generateSuggestions();
        }
        displaySuggestionsInModal();
    });
    
    suggestionsModalBody.addEventListener('click', e => { 
        if(e.target.classList.contains('use-suggestion-btn')) { 
            userPromptInput.value = e.target.dataset.suggestion; 
        } 
    });

    document.querySelectorAll('#qrsg-qr-modal .dropdown-item').forEach(item => { 
        item.addEventListener('click', handleBlueprintLink); 
    });
});
