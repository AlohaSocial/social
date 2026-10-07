<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<div class="atproto-migration" v-if="showMigration">
		<div class="migration-header">
			<h2>{{ $t('Bring your Bluesky account here') }}</h2>
			<button class="btn btn-icon" @click="closeMigration">
				<icon name="close" />
			</button>
		</div>
		
		<div class="migration-steps">
			<div class="step" :class="{ active: currentStep >= 1, completed: currentStep > 1 }">
				<div class="step-number">1</div>
				<div class="step-label">{{ $t('Enter Bluesky handle') }}</div>
			</div>
			<div class="step" :class="{ active: currentStep >= 2, completed: currentStep > 2 }">
				<div class="step-number">2</div>
				<div class="step-label">{{ $t('Create account here') }}</div>
			</div>
			<div class="step" :class="{ active: currentStep >= 3, completed: currentStep > 3 }">
				<div class="step-number">3</div>
				<div class="step-label">{{ $t('Import repository') }}</div>
			</div>
			<div class="step" :class="{ active: currentStep >= 4, completed: currentStep > 4 }">
				<div class="step-number">4</div>
				<div class="step-label">{{ $t('Update identity') }}</div>
			</div>
			<div class="step" :class="{ active: currentStep >= 5, completed: currentStep > 5 }">
				<div class="step-number">5</div>
				<div class="step-label">{{ $t('Activate') }}</div>
			</div>
		</div>
		
		<div class="migration-content">
			<!-- Step 1: Enter handle -->
			<div v-if="currentStep === 1" class="step-content">
				<p>{{ $t('Enter your Bluesky handle (e.g., alice.bsky.social) and an app password to begin.') }}</p>
				<form @submit.prevent="startMigration">
					<div class="form-group">
						<label>{{ $t('Bluesky handle') }}</label>
						<input type="text" v-model="form.handle" placeholder="alice.bsky.social" required />
					</div>
					<div class="form-group">
						<label>{{ $t('App password') }}</label>
						<input type="password" v-model="form.appPassword" placeholder="xxxx-xxxx-xxxx-xxxx" required autocomplete="off" />
						<p class="form-hint">{{ $t('Create an app password at <a href="https://bsky.app/settings/app-passwords" target="_blank">bsky.app/settings/app-passwords</a>') }}</p>
					</div>
					<button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? $t('Connecting...') : $t('Continue') }}</button>
				</form>
			</div>
			
			<!-- Step 2: Create account -->
			<div v-if="currentStep === 2" class="step-content">
				<p>{{ $t('Creating your account here with your existing DID...') }}</p>
				<div class="progress" v-if="loading">
					<div class="progress-bar" :style="{ width: progress + '%' }"></div>
				</div>
				<pre v-if="stepOutput" class="step-output">{{ stepOutput }}</pre>
			</div>
			
			<!-- Step 3: Import repository -->
			<div v-if="currentStep === 3" class="step-content">
				<p>{{ $t('Importing your Bluesky repository (posts, follows, likes)...') }}</p>
				<div class="progress" v-if="loading">
					<div class="progress-bar" :style="{ width: progress + '%' }"></div>
				</div>
				<pre v-if="stepOutput" class="step-output">{{ stepOutput }}</pre>
			</div>
			
			<!-- Step 4: Update identity -->
			<div v-if="currentStep === 4" class="step-content">
				<p>{{ $t('Updating your DID document to point to this server...') }}</p>
				<p>{{ $t('You will receive an email from Bluesky with a verification code.') }}</p>
				<div class="form-group" v-if="awaitingCode">
					<label>{{ $t('Verification code') }}</label>
					<input type="text" v-model="form.verificationCode" placeholder="123456" @keydown.enter="submitCode" />
					<button class="btn btn-primary" @click="submitCode" :disabled="loading">{{ loading ? $t('Verifying...') : $t('Verify') }}</button>
				</div>
				<pre v-if="stepOutput" class="step-output">{{ stepOutput }}</pre>
			</div>
			
			<!-- Step 5: Activate -->
			<div v-if="currentStep === 5" class="step-content">
				<div class="success-message">
					<icon name="check-circle" class="success-icon" />
					<h3>{{ $t('Migration complete!') }}</h3>
					<p>{{ $t('Your Bluesky account has been moved to this server. Your followers will automatically follow your new handle.') }}</p>
					<p class="new-handle">{{ $t('Your new handle:') }} @{{ newHandle }}</p>
					<button class="btn btn-primary" @click="finishMigration">{{ $t('Done') }}</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { useApi } from '../composables/useApi.js'

const emit = defineEmits(['update:modelValue', 'complete'])

const props = defineProps({
	modelValue: {
		type: Boolean,
		default: false
	}
})

const showMigration = computed({
	get: () => props.modelValue,
	set: (value) => emit('update:modelValue', value)
})

const api = useApi()

const currentStep = ref(1)
const loading = ref(false)
const progress = ref(0)
const stepOutput = ref('')
const awaitingCode = ref(false)
const newHandle = ref('')
const form = reactive({
	handle: '',
	appPassword: '',
	verificationCode: ''
})

const closeMigration = () => {
	showMigration.value = false
	resetForm()
}

const resetForm = () => {
	currentStep.value = 1
	loading.value = false
	progress.value = 0
	stepOutput.value = ''
	awaitingCode.value = false
	newHandle.value = ''
	form.handle = ''
	form.appPassword = ''
	form.verificationCode = ''
}

const startMigration = async () => {
	if (!form.handle || !form.appPassword) return
	
	loading.value = true
	currentStep.value = 2
	stepOutput.value = `Resolving ${form.handle}...`
	
	try {
		// Step 1: Resolve handle and get DID
		const response = await api.post('/api/atproto/migration/start', {
			handle: form.handle,
			appPassword: form.appPassword
		})
		
		stepOutput.value += '\nResolved DID: ' + response.data.did
		stepOutput.value += '\nOld PDS: ' + response.data.oldPds
		stepOutput.value += '\n\nCreating account here with existing DID...'
		
		// Step 2: Create account here
		const createResponse = await api.post('/api/atproto/migration/create-account', {
			did: response.data.did,
			serviceAuth: response.data.serviceAuth
		})
		
		stepOutput.value += '\nAccount created (deactivated)'
		stepOutput.value += '\n\nImporting repository...'
		
		currentStep.value = 3
		
		// Step 3: Import repository
		const importResponse = await api.post('/api/atproto/migration/import-repo', {
			did: response.data.did,
			oldPds: response.data.oldPds
		})
		
		// Poll import progress
		await pollImport(importResponse.data.importId)
		
		stepOutput.value += '\nRepository imported successfully'
		stepOutput.value += '\n\nUpdating identity (PLC operation)...'
		
		currentStep.value = 4
		awaitingCode.value = true
		
		// Step 4: Request PLC operation signature
		const plcResponse = await api.post('/api/atproto/migration/request-plc', {
			did: response.data.did
		})
		
		stepOutput.value += '\nPLC operation requested. Check your email for verification code.'
		
	} catch (error) {
		stepOutput.value += '\n\nError: ' + error.message
		loading.value = false
	}
}

const pollImport = async (importId) => {
	while (loading.value) {
		try {
			const response = await api.get('/api/atproto/migration/import-status', { importId })
			const status = response.data
			progress.value = status.progress
			stepOutput.value = `Importing: ${status.processed}/${status.total} records...`
			
			if (status.complete) {
				progress.value = 100
				break
			}
			
			await new Promise(r => setTimeout(r, 2000))
		} catch (error) {
			console.error('Import polling failed:', error)
			break
		}
	}
}

const submitCode = async () => {
	if (!form.verificationCode) return
	
	loading.value = true
	awaitingCode.value = false
	stepOutput.value += '\nSubmitting verification code...'
	
	try {
		const response = await api.post('/api/atproto/migration/submit-plc', {
			verificationCode: form.verificationCode
		})
		
		stepOutput.value += '\nPLC operation submitted successfully'
		stepOutput.value += '\n\nActivating account...'
		
		currentStep.value = 5
		
		// Step 5: Activate
		await api.post('/api/atproto/migration/activate')
		
		newHandle.value = response.data.newHandle
		stepOutput.value += '\nAccount activated!'
		
		loading.value = false
		
	} catch (error) {
		stepOutput.value += '\n\nError: ' + error.message
		loading.value = false
		awaitingCode.value = true
	}
}

const finishMigration = () => {
	emit('complete')
	closeMigration()
}
</script>

<style scoped>
.atproto-migration {
	position: fixed;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	width: 90%;
	max-width: 600px;
	max-height: 90vh;
	background: var(--bg-primary);
	border-radius: 12px;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
	z-index: 1000;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.migration-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--border-color);
}

.migration-header h2 {
	margin: 0;
	font-size: 1.125rem;
}

.migration-steps {
	display: flex;
	justify-content: space-between;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--border-color);
	background: var(--bg-secondary);
}

.step {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.25rem;
	flex: 1;
	text-align: center;
}

.step-number {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--border-color);
	color: var(--text-muted);
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 600;
	font-size: 0.875rem;
	transition: all 0.3s;
}

.step.active .step-number {
	background: var(--primary-color);
	color: white;
}

.step.completed .step-number {
	background: var(--success-color);
	color: white;
}

.step-label {
	font-size: 0.6875rem;
	color: var(--text-muted);
	white-space: nowrap;
}

.step.active .step-label {
	color: var(--primary-color);
	font-weight: 500;
}

.migration-content {
	flex: 1;
	padding: 1.5rem;
	overflow-y: auto;
	max-height: 60vh;
}

.step-content p {
	margin-bottom: 1rem;
	color: var(--text-secondary);
}

.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.375rem;
	font-weight: 500;
}

.form-group input {
	width: 100%;
	padding: 0.5rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	font-size: 1rem;
}

.form-hint {
	margin: 0.25rem 0 0 0;
	font-size: 0.75rem;
	color: var(--text-muted);
}

.form-hint a {
	color: var(--primary-color);
}

.progress {
	height: 6px;
	background: var(--border-color);
	border-radius: 3px;
	overflow: hidden;
	margin: 1rem 0;
}

.progress-bar {
	height: 100%;
	background: var(--primary-color);
	transition: width 0.3s;
}

.step-output {
	background: var(--bg-secondary);
	border: 1px solid var(--border-color);
	border-radius: 4px;
	padding: 1rem;
	font-size: 0.8125rem;
	line-height: 1.6;
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 300px;
	overflow-y: auto;
	margin-top: 1rem;
}

.success-message {
	text-align: center;
	padding: 2rem 1rem;
}

.success-icon {
	width: 64px;
	height: 64px;
	color: var(--success-color);
	margin-bottom: 1rem;
}

.success-message h3 {
	margin: 0 0 0.5rem 0;
}

.success-message p {
	color: var(--text-secondary);
	margin-bottom: 0.5rem;
}

.new-handle {
	font-size: 1.125rem;
	font-weight: 600;
	color: var(--primary-color);
	margin: 1rem 0;
}

@media (max-width: 640px) {
	.atproto-migration {
		width: 100%;
		height: 100%;
		max-height: 100%;
		border-radius: 0;
		top: 0;
		left: 0;
		transform: none;
	}
}
</style>